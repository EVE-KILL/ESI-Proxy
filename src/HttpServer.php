<?php

namespace EK;

use finfo;
use Monolog\Level;
use OpenSwoole\Http\Request;
use OpenSwoole\Http\Response;
use OpenSwoole\Http\Server;
use OpenSwoole\Runtime;
use OpenSwoole\Table;

class HttpServer
{
    protected Logger $logger;
    protected Server $server;
    protected Table $esiStatus;
    protected Table $esiCache;

    public function __construct(
        protected string $listen = '0.0.0.0',
        protected int $port = 9501,
        protected Level $loggerLevel = \Monolog\Level::Debug
    ) {
        $this->logger = new Logger($loggerLevel);
        $this->server = new Server($listen, $port);

        // Setup ESI Status table
        $this->esiStatus = new Table(1024);
        $this->esiStatus->column('limit', Table::TYPE_INT, 4);
        $this->esiStatus->column('reset', Table::TYPE_INT, 4);
        $this->esiStatus->create();

        // Set the values for the ESI Status table
        $this->esiStatus->set('error', ['limit' => 100, 'reset' => 60]);

        // Setup ESI Cache table
        $this->esiCache = new Table(1024);
        $this->esiCache->column('url', Table::TYPE_STRING, 1024);
        $this->esiCache->column('content', Table::TYPE_STRING, 1024*1024);
        $this->esiCache->column('content_type', Table::TYPE_STRING, 128);
        $this->esiCache->column('expires', Table::TYPE_INT, 10);
        $this->esiCache->column('status', Table::TYPE_INT, 4);
        $this->esiCache->create();
    }

    protected function handleRequest(Request $request, Response $response): void
    {
        $this->logger->log("Request received: {$request->server['request_uri']}");
        $this->logger->log('Current ESI Error Limit: ' . $this->esiStatus->get('error', 'limit'));
        $this->logger->log('Current ESI Error Limit Reset: ' . $this->esiStatus->get('error', 'reset'));

        if ($this->esiStatus->get('error', 'limit') <= 0) {
            $response->status(420);
            $response->end('Error limit reached, please try again in ' . $this->esiStatus->get('error', 'reset') . ' seconds');
            return;
        }

        // Get the request path
        $requestPath = $request->server['request_uri'];

        // Generate a cache key
        $cacheKey = md5($requestPath);

        // Check the cache for a result and return it if found
        if ($result = $this->esiCache->get($cacheKey)) {
            $response->status($result['status']);
            $response->header('Content-Type', $result['content_type']);
            $response->header('Expires', $result['expires']);
            $response->header('X-Cache', 'HIT');
            $response->end($result['content']);
            return;
        }

        // ESI URL
        $esiUrl = "https://esi.evetech.net";

        // Fetch the path from ESI
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, "{$esiUrl}{$requestPath}");
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 10);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, 5);

        $headers = [];
        $headers[] = 'User-Agent: EK-ESI-Proxy @ michael@karbowiak.dk';
        $headers[] = 'Accept: application/json';
        $headers[] = 'Content-Type: application/json';

        curl_setopt($curl, CURLOPT_HTTPHEADER, $headers);

        $responseBody = curl_exec($curl);
        $responseInfo = curl_getinfo($curl);

        // Get the error limit, and reset time from the headers
        $errorLimitRemaining = $responseInfo['http_x_esi_error_limit_remain'] ?? 100;
        $errorLimitReset = $responseInfo['http_x_esi_error_limit_reset'] ?? 60;
        $this->esiStatus->set('error', ['limit' => $errorLimitRemaining, 'reset' => $errorLimitReset]);

        // Insert it into the cache if the status is 200
        if ($responseInfo['http_code'] === 200) {
            $this->esiCache->set($cacheKey, [
                'url' => $requestPath,
                'content' => $responseBody,
                'content_type' => $responseInfo['content_type'],
                'expires' => time() + 3600,
                'status' => $responseInfo['http_code']
            ]);
        }

        $response->status($responseInfo['http_code']);
        $response->header('Content-Type', $responseInfo['content_type']);
        $response->header('X-Esi-Error-Limit', $errorLimitRemaining);
        $response->header('X-Esi-Error-Limit-Reset', $errorLimitReset);
        $response->header('X-Cache', 'MISS');
        // Pass through _ALL_ headers from the ESI response
        foreach ($responseInfo as $key => $value) {
            $response->header($key, $value);
        }
        $response->end($responseBody);
    }

    public function run(): void
    {
        $this->server->on('start', function (Server $server) {
            $this->logger->log("Server started at http://{$server->host}:{$server->port}");
        });

        $this->server->on('request', function ($request, $response) {
            $this->handleRequest($request, $response);
        });

        // Enable coroutine support for CURL
        Runtime::enableCoroutine(Runtime::HOOK_NATIVE_CURL);

        // Setup a ticker to clean out the esiCache table for expired entries
        $this->server->tick(60000, function () {
            $now = time();
            foreach ($this->esiCache as $key => $row) {
                if ($row['expires'] < $now) {
                    $this->logger->log("Removing expired cache entry: {$row['url']}");
                    $this->esiCache->del($key);
                }
            }
        });

        $options = [
            'host' => $this->listen,
            'port' => $this->port,
            'daemonize' => false,
            'worker_num' => 4,
            'max_request' => 10000,
            'dispatch_mode' => 2,
            'backlog' => 128,
            'reload_async' => true,
            'max_wait_time' => 60,
            'enable_coroutine' => true,
            'http_compression' => true,
            'http_compression_level' => 1,
            'buffer_output_size' => 4 * 1024 * 1024
        ];
        $this->server->start($options);
    }
}
