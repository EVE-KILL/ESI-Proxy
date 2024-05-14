<?php

namespace EK;

use Monolog\Level;

class Bootstrap
{
    protected HttpServer $server;
    public function __construct(
        string $listen = '0.0.0.0',
        int $port = 9501,
        Level $loggerLevel = Level::Debug
    ) {
        $this->server = new HttpServer($listen, $port, $loggerLevel);
    }

    public function run(): void
    {
        $this->server->run();
    }
}
