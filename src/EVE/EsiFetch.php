<?php

namespace EK\EVE;

use bandwidthThrottle\tokenBucket\BlockingConsumer;
use bandwidthThrottle\tokenBucket\Rate;
use bandwidthThrottle\tokenBucket\storage\FileStorage;
use bandwidthThrottle\tokenBucket\TokenBucket;
use EK\Cache\Cache;
use EK\Logger\Logger;
use EK\Proxy\Proxy;
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

    public function fetch(string $path, array $query = [], string $requestBody = '', array $headers = [], array $options = [], bool $waitForEsiErrorReset = false, ?BlockingConsumer &$blockingConsumer = null, string $requestMethod = 'GET'): array
    {
        // Make sure we aren't banned
        if ($this->areWeBanned()) {
            return [
                'status' => 401,
                'headers' => [],
                'body' => 'You are banned from ESI'
            ];
        }

        // Make sure that we aren't against any error limits
        $esiErrorLimit = $this->getEsiErrorLimit();
        if ($esiErrorLimit['limit'] <= 0 && $waitForEsiErrorReset === false) {
            return [
                'status' => 420,
                'headers' => [],
                'body' => 'Error limit reached, please try again in ' . $esiErrorLimit['reset'] . ' seconds'
            ];
        } else if ($waitForEsiErrorReset === true && $esiErrorLimit['limit'] <= 0) {
            // We should just sleep until the next reset
            $this->logger->log('Sleeping for ' . $esiErrorLimit['reset'] . ' seconds');
            sleep($esiErrorLimit['reset']);
        }

        // Get the cache key for this request
        $cacheKey = $this->getCacheKey($path, $query, $headers);

        // If the cache key exists, return the cached response
        if ($this->cache->exists($cacheKey)) {
            $result = $this->cache->get($cacheKey);
            if (isset($options['skip304']) && $options['skip304'] === false) {
                $result['status'] = 304;
            }
            $result['headers']['X-EK-Cache'] = 'HIT';
            return $result;
        }

        // Consume a token from the rate limit bucket if we have a rate limit
        if ($blockingConsumer !== null) {
            try {
                $blockingConsumer->consume(1);
            } catch (\Exception $e) {
                // Wait 50ms and try again
                usleep(50000);
                $blockingConsumer->consume(1);
            }
        }

        // Make the request to the ESI API
        $response = $this->client->request($requestMethod, $path, [
            'query' => $query,
            'headers' => $headers,
            'body' => $requestBody,
            'http_errors' => false
        ]);

;
        // Get the status code from the response
        $statusCode = $response->getStatusCode() ?? 503;

        // Get the contents of the response
        $contents = $response->getBody()->getContents();

        // If the status code is 401, we are banned (It also needs to have the message, otherwise it's not official - or something)
        if ($statusCode === 401 && str_contains($contents, 'You have been banned from using ESI.')) {
            $this->cache->set('esi_banned', true, 0);
        }

        // Get the expires header from the response (The Expires and Date are in GMT)
        $now = new \DateTime('now', new \DateTimeZone('GMT'));
        $expires = $response->getHeader('Expires')[0] ?? $now->format('D, d M Y H:i:s T');
        $serverTime = $response->getHeader('Date')[0] ?? $now->format('D, d M Y H:i:s T');
        $expiresInSeconds = strtotime($expires) - strtotime($serverTime) ?? 60;

        // If the expires header is set, and the status code is 200, cache the response
        if ($expiresInSeconds > 0 && in_array($statusCode, [200, 304])) {
            $this->cache->set($cacheKey, [
                'status' => $statusCode,
                'headers' => array_merge($response->getHeaders(), ['X-EK-Cache' => 'MISS']),
                'body' => $contents
            ], $expiresInSeconds);
        }

        // Set the error limit from the response headers
        $this->setEsiErrorLimit(
            $response->getHeader('X-Esi-Error-Limit-Remain')[0] ?? $esiErrorLimit['limit'],
            $response->getHeader('X-Esi-Error-Limit-Reset')[0] ?? $esiErrorLimit['reset']
        );

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

    private function setEsiErrorLimit(int $limit, int $reset): void
    {
        $this->cache->set('esi_error_limit', ['limit' => $limit, 'reset' => $reset], $reset);
    }

    private function areWeBanned(): bool
    {
        return $this->cache->exists('esi_banned');
    }
}