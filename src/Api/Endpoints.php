<?php

namespace EK\Api;

use EK\EVE\EsiFetch;
use EK\Logger\Logger;
use Nyholm\Psr7\Response;
use Nyholm\Psr7\ServerRequest;

class Endpoints
{
    public int $priority = 0;
    public array $routes = [];
    public int $hardRateLimit = 0;
    public int $rateLimit = 1000;
    public string $userAgent = 'ESI-PROXY/1.0';

    public function __construct(
        protected EsiFetch $esiFetcher,
        protected Logger $logger
    ) {
        // Ensure that the hard rate limit always takes precedence (But ignore it if it's 0)
        if ($this->rateLimit > 0 || $this->hardRateLimit > 0) {
            if ($this->hardRateLimit > 0 && $this->hardRateLimit < $this->rateLimit) {
                $this->rateLimit = $this->hardRateLimit;
            }
            $this->logger->log(get_class($this) . ' rate limit is enabled and set to ' . $this->rateLimit);
        }
    }

    public function fetch(ServerRequest $request, Response $response): array
    {
        // We need to get the path, query, headers and client IP
        $path = $request->getUri()->getPath();
        $query = $request->getQueryParams();
        ksort($query);
        $requestMethod = $request->getMethod();
        $requestBody = $request->getBody()->getContents();

        $headers = [
            'User-Agent' => $this->userAgent,
            'Accept' => 'application/json',
        ];

        // Add Authorization header if it exists
        if ($request->hasHeader('Authorization') || $request->hasHeader('authorization')) {
            $headers['authorization'] = $request->getHeader('Authorization');
        }

        // Get the data from ESI
        return $this->esiFetcher->fetch($path, $query, $requestBody, $headers, $requestMethod);
    }

    public function handle(ServerRequest $request, Response $response): Response
    {
        $result = $this->fetch($request, $response);

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
}
