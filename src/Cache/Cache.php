<?php

namespace EK\Cache;

use EK\Logger\Logger;
use Predis\Client;
use Predis\Response\Status;

class Cache
{
    protected Client $redis;
    public function __construct(
        protected Logger $logger
    ) {
        $redisHost = $_ENV['REDIS_HOST'];
        $redisPort = $_ENV['REDIS_PORT'];
        $redisPassword = $_ENV['REDIS_PASSWORD'];
        $redisDatabase = $_ENV['REDIS_DATABASE'];

        $this->redis = new Client([
            'scheme' => 'tcp',
            'host' => $redisHost,
            'port' => $redisPort,
            'password' => $redisPassword,
            'database' => $redisDatabase,
        ], [
            'prefix' => 'esi:',
            'persistent' => true,
            'timeout' => 10,
            'read_write_timeout' => 5,
            'tcp_keepalive' => 1,
            'tcp_nodelay' => true,
            'throw_errors' => false,
        ]);
    }

    public function clean(): void
    {
        $this->redis->flushDB();
    }

    public function get(string $key): mixed
    {
        $result = $this->redis->get($key);
        if ($result === null) {
            return null;
        }

        return json_decode($result, true);
    }

    public function set(string $key, mixed $value, int $ttl = 0): Status
    {
        if ($ttl > 0) {
            return $this->redis->setex($key, $ttl, json_encode($value));
        }

        return $this->redis->set($key, json_encode($value));
    }

    public function exists(string $key): bool
    {
        return $this->redis->exists($key);
    }
}
