<?php

namespace EK\Cache;

use EK\Logger\Logger;
use OpenSwoole\Table;

class Cache
{
    protected \PDO $sqlite;
    public function __construct(
        protected Logger $logger
    ) {
        // Use sqlite for the cache, and store it on disk in /data/cache.sqlite
        $sqlitePath = dirname(__DIR__, 2) . '/data/cache.sqlite';
        $this->sqlite = new \PDO('sqlite:' . $sqlitePath);

        // Create the cache table
        $this->sqlite->exec('CREATE TABLE IF NOT EXISTS cache (key STRING PRIMARY KEY, value TEXT, ttl INTEGER)');
    }

    public function clean(): void
    {
        $this->sqlite->exec('DELETE FROM cache WHERE ttl > 0 AND ttl < ' . time());
    }

    public function get(string $key): ?array
    {
        $statement = $this->sqlite->prepare('SELECT value FROM cache WHERE key = :key');
        $statement->execute([':key' => $key]);
        $result = $statement->fetch(\PDO::FETCH_ASSOC);
        if(isset($result['value'])) {
            return json_decode($result['value'], true);
        }

        return null;
    }

    public function set(string $key, array $value, int $ttl = 0): bool
    {
        $statement = $this->sqlite->prepare('INSERT OR REPLACE INTO cache (key, value, ttl) VALUES (:key, :value, :ttl)');
        return $statement->execute([
            ':key' => $key,
            ':value' => json_encode($value),
            ':ttl' => $ttl > 0 ? time() + $ttl : 0
        ]);
    }

    public function exists(string $key): bool
    {
        $statement = $this->sqlite->prepare('SELECT COUNT(*) FROM cache WHERE key = :key');
        $statement->execute([':key' => $key]);
        return $statement->fetchColumn() > 0;
    }
}