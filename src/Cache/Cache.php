<?php

namespace EK\Cache;

use EK\Logger\Logger;
use EK\Server\Server;

class Cache
{
    protected \Redis $redis;
    public function __construct(
        protected Server $server,
        protected Logger $logger
    ) {
        $redisHost = $this->server->getOptions()['redisHost'];
        $redisPort = $this->server->getOptions()['redisPort'];
        $redisPassword = $this->server->getOptions()['redisPassword'];
        $redisDatabase = $this->server->getOptions()['redisDatabase'];

        $this->redis = new \Redis();
        $this->redis->connect($redisHost, $redisPort);
        $this->redis->select($redisDatabase);
        if ($redisPassword !== '') {
            $this->redis->auth($redisPassword);
        }
    }

    public function clean(): void
    {
        $this->redis->flushDB();
    }

    public function get(string $key): mixed
    {
        return json_decode($this->redis->get($key), true);
    }

    public function set(string $key, mixed $value, int $ttl = 0): bool
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