<?php

namespace EK\Server;

use EK\EVEKILL\DialHomeDevice;
use EK\Logger\Logger;
use Kcs\ClassFinder\Finder\ComposerFinder;
use League\Container\Container;
use OpenSwoole\Core\Psr\ServerRequest as Request;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response;

class Server
{
    protected array $options = [];

    public function __construct(
        protected Container $container,
        protected Logger $logger
    ) {

    }
    public function run(): void
    {
        // Start Slim
        $app = AppFactory::create();

        $app->get('/', function (Request $request, Response $response) {
            $response->getBody()->write(file_get_contents(__DIR__ . '/templates/index.html'));
            return $response;
        });

        $app->get('/status', function (Request $request, Response $response) {
            $response->getBody()->write(json_encode(['status' => 'OK']));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // Load all the endpoints
        $endpoints = new ComposerFinder();
        $endpoints->inNamespace('EK\\Endpoints');
        $sortedEndpoints = [];

        // Sort the endpoints by priority
        foreach($endpoints as $className => $reflection) {
            $endpoint = $this->container->get($className);
            $sortedEndpoints[$endpoint->priority] = $endpoint;
        }

        // Sort the endpoints by priority
        ksort($sortedEndpoints);

        // Add the routes to the app
        foreach($sortedEndpoints as $endpoint) {
            foreach($endpoint->routes as $route => $methods) {
                $app->map($methods, $route, function (Request $request, Response $response, array $args) use ($endpoint) {
                    return $endpoint->handle($request, $response);
                });
            }
        }

        $server = new \OpenSwoole\Http\Server($this->options['host'], $this->options['port']);
        $server->on('start', function ($server) {
            $this->logger->log("Swoole http server is started at http://{$this->options['host']}:{$this->options['port']}");

            if ($this->options['dialHome'] === true && $this->options['externalAddress'] !== '') {
                $this->logger->log('Calling home');
                /** @var DialHomeDevice $dialHomeDevice */
                $dialHomeDevice = $this->container->get(DialHomeDevice::class);
                $response = $dialHomeDevice->callHome($this->options['host'], $this->options['port'], $this->options['externalAddress']);
                $this->logger->log('DialHomeDevice response: ' . $response['message'] ?? 'Unknown error');
            }
        });

        $server->handle(function ($request) use ($app) {
            $response = $app->handle($request);

            $path = $request->getUri()->getPath();
            $requestParams = http_build_query($request->getQueryParams());
            $wasServedFromCache = $response->getHeader('X-EK-Cache')[0] ?? '' === 'HIT';
            $this->logger->log("Request received: {$path}{$requestParams}", ['served-from-cache' => $wasServedFromCache]);

            return $response;
        });

        /**
         * If the dialHome flag is set to true, we will call home on startup, and once every hour
         * This is done so that the EVE-KILL Proxy knows we're alive, and can be used for proxying requests
         * If it is false, we are working in standalone proxy mode
         */
        if ($this->options['dialHome'] === true && $this->options['externalAddress'] !== '') {
            // Setup a tick to call home every hour
            $server->tick(3600000, function () {
                $this->logger->log('Calling home');
                /** @var DialHomeDevice $dialHomeDevice */
                $dialHomeDevice = $this->container->get(DialHomeDevice::class);
                $response = $dialHomeDevice->callHome($this->options['host'], $this->options['port'], $this->options['externalAddress']);
                $this->logger->log('DialHomeDevice response: ' . $response['message'] ?? 'Unknown error');
            });
        }

        $server->set([
            'daemonize' => false,
            'worker_num' => $this->getOptions()['workers'] ?? 4,
            'max_request' => 10000,
            'dispatch_mode' => 2,
            'backlog' => 128,
            'reload_async' => true,
            'max_wait_time' => 60,
            'enable_coroutine' => true,
            'http_compression' => true,
            'http_compression_level' => 1,
            'buffer_output_size' => 4 * 1024 * 1024
        ]);

        $server->start();
    }

    public function setOptions(array $options): void
    {
        $this->options = $options;
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}