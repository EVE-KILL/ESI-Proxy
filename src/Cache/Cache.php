<?php

namespace EK\Cache;

use EK\Logger\Logger;
use OpenSwoole\Table;

class Cache
{
    protected Table $cacheTable;
    public function __construct(
        protected Logger $logger
    ) {
        $this->cacheTable = new Table(1024);
        $this->cacheTable->column('key', Table::TYPE_STRING, 64);
        $this->cacheTable->column('value', Table::TYPE_STRING, 1024 * 1024);
        $this->cacheTable->column('ttl', Table::TYPE_INT, 4);
        $this->cacheTable->create();
    }

    public function clean(): void
    {
        foreach ($this->cacheTable as $key => $value) {
            // If the value has no TTL, skip it (we want to keep it _FOREVER_)
            if ($value['ttl'] === 0) {
                continue;
            }

            if ($value['ttl'] && $value['ttl'] < time()) {
                $this->logger->log("Cleaning cache key: {$key}");
                $this->cacheTable->del($key);
            }
        }
    }

    public function get(string $key): mixed
    {
        $value = $this->cacheTable->get($key);
        if ($value) {
            return json_decode($value['value'], true);
        }
        return null;
    }

    public function set(string $key, array $value, int $ttl = 0): bool
    {
        return $this->cacheTable->set($key, [
            'value' => json_encode($value),
            'ttl' => $ttl ? time() + $ttl : 0
        ]);
    }

    public function exists(string $key): bool
    {
        return $this->cacheTable->exists($key);
    }
}