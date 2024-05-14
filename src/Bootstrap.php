<?php

namespace EK;

use Composer\Autoload\ClassLoader;
use EK\Cache\Cache;
use EK\EVE\EsiFetch;
use EK\EVEKILL\DialHomeDevice;
use EK\Logger\Logger;
use EK\Proxy\Proxy;
use League\Container\Container;
use League\Container\ReflectionContainer;
use OpenSwoole\Core\Psr\ServerRequest as Request;
use OpenSwoole\Http\Server;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response;

class Bootstrap
{
    public function __construct(
        protected ClassLoader $autoloader,
        protected ?Container $container = null
    ) {
        $this->buildContainer();
    }

    protected function buildContainer(): void
    {
        $this->container = $this->container ?? new Container();

        // Register the reflection container
        $this->container->delegate(
            new ReflectionContainer(true)
        );

        // Add the autoloader
        $this->container->add(ClassLoader::class, $this->autoloader)
            ->setShared(true);

        // Add the container to itself
        $this->container->add(Container::class, $this->container)
            ->setShared(true);

        // Add the cache
        $this->container->add(Cache::class, new Cache($this->container->get(Logger::class)))
            ->setShared(true);
    }

    public function run(string $host = '127.0.0.1', int $port = 9501, string $externalAddress = '', bool $dialHome = false): void
    {
        // Start Slim
        $app = AppFactory::create();

        // Get the logger
        /** @var Logger $logger */
        $logger = $this->container->get(Logger::class);

        $app->get('/', function (Request $request, Response $response) {
            $response->getBody()->write('Please refer to https://esi.evetech.net/ui/ for the ESI documentation');
            return $response;
        });

        $app->get('/status', function (Request $request, Response $response) {
            $response->getBody()->write('OK');
            return $response;
        });

        // Catch-all route
        $app->get('/{routes:.+}', function (Request $request, Response $response) {
            $esiFetcher = $this->container->get(EsiFetch::class);

            // We need to get the path, query, headers and client IP
            $path = $request->getUri()->getPath();
            $query = $request->getQueryParams();
            $headers = [
                'User-Agent' => 'EVEKILL ESI Proxy/1.0',
                'Accept' => 'application/json',
            ];

            // Add Authorization header if it exists
            if ($request->hasHeader('Authorization')) {
                $headers['Authorization'] = $request->getHeader('Authorization')[0];
            }

            // Get the client IP
            $clientIp = $request->getServerParams()['remote_addr'];

            // Get the data from ESI
            $result = $esiFetcher->fetch($path, $clientIp, $query, $headers);

            // Add all the headers from ESI to the response
            foreach ($result['headers'] as $header => $value) {
                $response = $response->withHeader($header, $value);
            }

            // Set the status code
            $response = $response->withStatus($result['status']);

            // Write it to the response
            $response
                ->getBody()
                ->write($result['body']);

            return $response;
        });

        $server = new Server($host, $port);
        $server->on('start', function ($server) use ($host, $port, $logger, $dialHome, $externalAddress) {
            $logger->log("Swoole http server is started at http://{$host}:{$port}");

            if ($dialHome === true && $externalAddress !== '') {
                $logger->log('Calling home');
                /** @var DialHomeDevice $dialHomeDevice */
                $dialHomeDevice = $this->container->get(DialHomeDevice::class);
                $response = $dialHomeDevice->callHome($host, $port, $externalAddress);
                $logger->log('DialHomeDevice response: ' . $response['message'] ?? 'Unknown error');
            }
        });

        $server->handle(function ($request) use ($app, $logger) {
            $path = $request->getUri()->getPath();
            $requestParams = http_build_query($request->getQueryParams());
            $logger->log("Request received: {$path}{$requestParams}");
            return $app->handle($request);
        });

        // Setup a tick to clean the cache every minute
        $server->tick(60000, function () use ($logger) {
            $this->container->get(Cache::class)->clean();
        });

        /**
         * If the dialHome flag is set to true, we will call home on startup, and once every hour
         * This is done so that the EVE-KILL Proxy knows we're alive, and can be used for proxying requests
         * If it is false, we are working in standalone proxy mode
         */
        if ($dialHome === true && $externalAddress !== '') {
            // Setup a tick to call home every hour
            $server->tick(3600000, function () use ($logger, $host, $port, $externalAddress) {
                $logger->log('Calling home');
                /** @var DialHomeDevice $dialHomeDevice */
                $dialHomeDevice = $this->container->get(DialHomeDevice::class);
                $response = $dialHomeDevice->callHome($host, $port, $externalAddress);
                $logger->log('DialHomeDevice response: ' . $response['message'] ?? 'Unknown error');
            });
        }

        $server->start();
    }
}
