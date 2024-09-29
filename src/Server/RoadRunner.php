<?php

namespace EK\Server;

use EK\Logger\Logger;
use Kcs\ClassFinder\Finder\ComposerFinder;
use League\Container\Container;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;
use Slim\App;
use Slim\Factory\AppFactory;
use Spiral\RoadRunner\Http\PSR7Worker;
use Spiral\RoadRunner\Worker;

class RoadRunner
{
    protected array $options = [];

    public function __construct(
        protected Container $container,
        protected Logger $logger
    ) {}

    public function run(): void {
        $psr17Factory = new Psr17Factory();
        $worker = Worker::create();
        $worker = new PSR7Worker($worker, $psr17Factory, $psr17Factory, $psr17Factory);

        $slimFramework = $this->initializeSlim();

        while ($request = $worker->waitRequest()) {
            try {
                $response = $slimFramework->handle($request);
                $worker->respond($response);
            } catch (\Throwable $e) {
                $worker->getWorker()->error((string) $e);
            }
        }
    }

    private function initializeSlim(): App
    {
        $psr17Factory = new Psr17Factory();
        $slimFramework = AppFactory::create($psr17Factory, $this->container);

        $slimFramework = $this->loadRoutes($slimFramework);

        return $slimFramework;
    }

    private function loadRoutes(App $app): App
    {
        $app->get('/', function (ServerRequest $request, Response $response) {
            $response->getBody()->write(file_get_contents(__DIR__ . '/templates/index.html'));
            return $response;
        });

        $app->get('/status', function (ServerRequest $request, Response $response) {
            $response->getBody()->write(json_encode(['status' => 'OK']));
            return $response->withHeader('Content-Type', 'application/json');
        });

        $app->get('/esi', function (ServerRequest $request, Response $response) {
            $esiPage = file_get_contents('https://esi.evetech.net');
            $response->getBody()->write($esiPage);
            return $response;
        });

        // Load all the endpoints
        $endpoints = new ComposerFinder();
        $endpoints->inNamespace('EK\\Endpoints');
        $sortedEndpoints = [];

        // Sort the endpoints by priority
        foreach ($endpoints as $className => $reflection) {
            $endpoint = $this->container->get($className);
            $sortedEndpoints[$endpoint->priority] = $endpoint;
        }

        // Sort the endpoints by priority
        ksort($sortedEndpoints);

        // Add the routes to the app
        foreach ($sortedEndpoints as $endpoint) {
            foreach ($endpoint->routes as $route => $methods) {
                $app->map($methods, $route, function (ServerRequest $request, Response $response, array $args) use ($endpoint) {
                    return $endpoint->handle($request, $response);
                });
            }
        }

        // Add a catch all route that just replies it doesn't exist
        $app->map(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], '/{route:.*}', function (ServerRequest $request, Response $response) {
            $response->getBody()->write(json_encode(['error' => 'Route not found']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(404);
        });

        return $app;
    }
}
