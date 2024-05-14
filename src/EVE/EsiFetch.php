<?php

namespace EK\EVE;

use EK\Cache\Cache;
use EK\Proxy\Proxy;
use GuzzleHttp\Client;

class EsiFetch
{
    protected Client $client;
    public function __construct(
        protected Cache $cache,
        protected string $baseUri = 'https://esi.evetech.net',
        protected string $version = 'latest'
    ) {
        $this->client = new Client([
            'base_uri' => $this->baseUri,
            'request.options' => [
                'headers' => [
                    'User-Agent' => 'EVE-KILL ESI Proxy/1.0'
                ]
            ]
        ]);
    }

    public function fetch(string $path, string $clientIp, array $query = [], array $headers = []): array
    {
        // Ensure that one client isn't causing excessive errors
        if ($this->getErrorsByIP($clientIp) >= 5) {
            return [
                'error' => 'You have exceeded the error limit, please try again in a minute'
            ];
        }

        // Make sure we aren't banned
        if ($this->areWeBannedYet()) {
            return [
                'error' => 'You are banned from ESI'
            ];
        }

        // Make sure that we aren't against any error limits
        $esiErrorLimit = $this->getEsiErrorLimit();
        if ($esiErrorLimit['limit'] <= 0) {
            return [
                'error' => 'Error limit reached, please try again in ' . $esiErrorLimit['reset'] . ' seconds'
            ];
        }

        // Get the cache key for this request
        $cacheKey = $this->getCacheKey($path, $query, $headers);

        // If the cache key exists, return the cached response
        if ($this->cache->exists($cacheKey)) {
            return $this->cache->get($cacheKey);
        }

        // Make the request to the ESI API using a random proxy from $this->proxy->getRandom()
        $response = $this->client->request('GET', $path, [
            'query' => $query,
            'headers' => $headers
        ]);

        // Get the status code from the response
        $statusCode = $response->getStatusCode();

        // Get the contents of the response
        $contents = $response->getBody()->getContents();

        // Get the expires header from the response (The Expires and Date are in GMT)
        $expires = $response->getHeader('Expires')[0];
        $serverTime = $response->getHeader('Date')[0];
        $expiresInSeconds = strtotime($expires) - strtotime($serverTime);

        // If the expires header is set, and the status code is 200, cache the response
        if ($expires && in_array($statusCode, [200, 304])) {
            $this->cache->set($cacheKey, [
                'status' => $statusCode,
                'headers' => $response->getHeaders(),
                'body' => $contents
            ], $expiresInSeconds);
        }

        // Set the error limit from the response headers
        $this->setEsiErrorLimit(
            $response->getHeader('X-Esi-Error-Limit-Remain')[0] ?? $esiErrorLimit['limit'],
            $response->getHeader('X-Esi-Error-Limit-Reset')[0] ?? $esiErrorLimit['reset']
        );

        // If we get an error status code, increment the error count for this IP
        if (!in_array($statusCode, [200, 304, 404])) {
            $this->incrementErrorsByIP($clientIp);
        }

        // Return the response as an array
       return [
           'status' => $statusCode,
           'headers' => $response->getHeaders(),
           'body' => $contents
       ];
    }

    private function getCacheKey(string $path, array $query, array $headers): string
    {
        return md5($path . json_encode($query) . json_encode($headers));
    }

    private function getEsiErrorLimit(): array
    {
        return $this->cache->get('esi_error_limit') ?? ['limit' => 100, 'reset' => 60];
    }

    private function setEsiErrorLimit(int $limit, int $reset): void
    {
        $this->cache->set('esi_error_limit', ['limit' => $limit, 'reset' => $reset], 0);
    }

    private function areWeBannedYet(): bool
    {
        return $this->cache->exists('esi_banned');
    }

    private function getErrorsByIP(string $clientIp): int
    {
        return $this->cache->get('esi_errors_' . $clientIp) ?? 0;
    }

    private function incrementErrorsByIP(string $clientIp): void
    {
        $errors = $this->getErrorsByIP($clientIp);
        $this->cache->set('esi_errors_' . $clientIp, $errors + 1, 60);
    }
}