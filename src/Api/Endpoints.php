<?php

namespace EK\Api;

use bandwidthThrottle\tokenBucket\BlockingConsumer;
use bandwidthThrottle\tokenBucket\Rate;
use bandwidthThrottle\tokenBucket\storage\FileStorage;
use bandwidthThrottle\tokenBucket\TokenBucket;
use EK\Bootstrap;
use EK\EVE\EsiFetch;
use EK\Logger\Logger;
use EK\Server\Server;
use OpenSwoole\Core\Psr\ServerRequest as Request;
use Slim\Psr7\Response;

class Endpoints
{
    public int $priority = 0;
    public array $routes = [];
    public int $hardRateLimit = 0;
    public int $rateLimit = 500;
    public string $userAgent = 'EVEKill/1.0';
    protected BlockingConsumer $rateLimitBucket;

    public function __construct(
        protected Server $server,
        protected EsiFetch $esiFetcher,
        protected Logger $logger
    ) {
        $this->rateLimit = $this->options('rateLimit', 500);
        $this->userAgent = $this->options('userAgent', 'EVE-KILL ESI Proxy/1.0');

        // Ensure that the hard rate limit always takes precedence (But ignore it if it's 0)
        if ($this->rateLimit > 0 || $this->hardRateLimit > 0) {
            if ($this->hardRateLimit > 0 && $this->hardRateLimit < $this->rateLimit) {
                $this->rateLimit = $this->hardRateLimit;
            }
            $this->logger->log(get_class($this) . ' rate limit is enabled and set to ' . $this->rateLimit);

            // Create the rate limit bucket
            $storage = new FileStorage('/tmp/' . get_class($this) . '_rate_limit.bucket');
            $rate = new Rate($this->rateLimit, Rate::SECOND);
            $bucket = new TokenBucket($this->rateLimit, $rate, $storage);
            $bucket->bootstrap($this->rateLimit);
            $this->rateLimitBucket = new BlockingConsumer($bucket);
        }
    }

    public function fetch(Request $request, Response $response, array $args): array
    {
        // We need to get the path, query, headers and client IP
        $path = $request->getUri()->getPath();
        $query = $request->getQueryParams();

        // Generate a semaphore key that locks the request till it's done
        // To prevent multiple concurrent hitting the same url and not getting the cache
        $semaphoreKey = md5($path . '?' . http_build_query($query));

        // Create a temporary file with the semaphore key
        $file = '/tmp/' . $semaphoreKey;
        file_put_contents($file, '');

        // Lock the semaphore
        $semaphore = sem_get(ftok($file, 'a'));
        sem_acquire($semaphore);

        $headers = [
            'User-Agent' => $this->userAgent,
            'Accept' => 'application/json',
        ];

        // Add Authorization header if it exists
        if ($request->hasHeader('Authorization')) {
            $headers['Authorization'] = $request->getHeader('Authorization')[0];
        }

        // Get the data from ESI
        $result = $this->esiFetcher->fetch($path, $query, $headers);

        // Release the semaphore
        sem_release($semaphore);

        // Remove the semaphore file
        unlink($file);

        return $result;
    }

    public function handle(Request $request, Response $response, array $args): Response
    {
        $result = $this->fetch($request, $response, $args);

        // Add all the headers from ESI to the response
        foreach ($result['headers'] as $header => $value) {
            $response = $response->withHeader($header, $value);
        }

        // Set the status code
        $response = $response->withStatus($result['status'] ?? 200);

        // Write it to the response
        $response
            ->getBody()
            ->write($result['body']);

        return $response;
    }

    public function options(string $key, mixed $default): mixed
    {
        return $this->server->getOptions()[$key] ?? $default;
    }
}