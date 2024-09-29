<?php

namespace EK\EVE;

use bandwidthThrottle\tokenBucket\BlockingConsumer;
use EK\Cache\Cache;
use EK\Logger\Logger;
use GuzzleHttp\Client;

class EsiFetch
{
    protected Client $client;

    public function __construct(
        protected Cache $cache,
        protected Logger $logger,
        protected string $baseUri = 'https://esi.evetech.net',
        protected string $version = 'latest'
    ) {
        $this->client = new Client([
            'base_uri' => $this->baseUri
        ]);
    }

    public function fetch(string $path, array $query = [], string $requestBody = '', array $headers = [], string $requestMethod = 'GET'): array
    {
        // Get the cache key for this request
        $cacheKey = $this->getCacheKey($path, $query, $headers);

        // If the cache key exists, return the cached response
        if ($this->cache->exists($cacheKey)) {
            $result = $this->cache->get($cacheKey);
            $result['headers']['X-EK-Cache'] = 'HIT';
            return $result;
        }

        // Make the request to the ESI API
        $response = $this->client->request($requestMethod, $path, [
            'query' => $query,
            'headers' => $headers,
            'body' => $requestBody,
            'timeout' => 30,
            'http_errors' => false
        ]);

        // Get the status code from the response
        $statusCode = $response->getStatusCode() ?? 503;

        // Get the contents of the response
        $contents = $response->getBody()->getContents();

        // Get the expires header from the response (The Expires and Date are in GMT)
        $now = new \DateTime('now', new \DateTimeZone('GMT'));
        $expires = $response->getHeader('Expires')[0] ?? $now->format('D, d M Y H:i:s T');
        $serverTime = $response->getHeader('Date')[0] ?? $now->format('D, d M Y H:i:s T');
        $expiresInSeconds = strtotime($expires) - strtotime($serverTime) ?? 60;
        $expiresInSeconds = abs($expiresInSeconds);

        // If the expires header is set, and the status code is 200, cache the response
        if ($expiresInSeconds > 0 && in_array($statusCode, [200, 304])) {
            $this->cache->set($cacheKey, [
                'status' => $statusCode,
                'headers' => array_merge($response->getHeaders(), ['X-EK-Cache' => 'MISS']),
                'body' => $contents
            ], $expiresInSeconds);
        }

        // Retrieve error limit remaining and reset time from headers
        $esiErrorLimitRemaining = (int) ($response->getHeader('X-Esi-Error-Limit-Remain')[0] ?? 100);
        $esiErrorLimitReset = (int) ($response->getHeader('X-Esi-Error-Limit-Reset')[0] ?? 0);

        // Cache the values
        $this->cache->set('esi_error_limit_remaining', $esiErrorLimitRemaining);
        $this->cache->set('esi_error_limit_reset', $esiErrorLimitReset);

        // Calculate progressive usleep time (in microseconds) based on inverse of error limit remaining
        if ($esiErrorLimitRemaining < 100) {
            // Error limit remaining should inversely affect the sleep time
            // The closer it is to zero, the longer the sleep
            $maxSleepTimeInMicroseconds = $esiErrorLimitReset * 1000000; // max sleep time, e.g., reset in seconds converted to microseconds

            // Calculate the inverse factor (higher remaining errors = lower sleep)
            $inverseFactor = (100 - $esiErrorLimitRemaining) / 100;

            // Exponentially scale the sleep time as remaining errors approach zero
            $sleepTimeInMicroseconds = (int) ($inverseFactor * $inverseFactor * $maxSleepTimeInMicroseconds);

            // Ensure sleep time is not too short, minimum of 1 millisecond (1000 microseconds)
            $sleepTimeInMicroseconds = max(1000, $sleepTimeInMicroseconds);

            // Apply usleep (sleep in microseconds)
            usleep($sleepTimeInMicroseconds);
        }
        // Return the response as an array
        return [
            'status' => $statusCode,
            'headers' => array_merge($response->getHeaders(), ['X-EK-Cache' => 'MISS']),
            'body' => $contents
        ];
    }

    private function getCacheKey(string $path, array $query, array $headers): string
    {
        return md5($path . json_encode($query) . json_encode($headers));
    }

    private function getEsiErrorLimit(): array
    {
        $result = $this->cache->get('esi_error_limit');
        if (!$result) {
            return ['limit' => 100, 'reset' => 60];
        }
        return $result;
    }
}
