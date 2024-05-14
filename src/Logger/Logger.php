<?php

namespace EK\Logger;

use Monolog\Level;
use Monolog\Logger as MonologLogger;

class Logger
{
    protected MonologLogger $logger;

    public function __construct(
    )
    {
        $this->logger = new MonologLogger('http-server');
        $this->logger->pushHandler(new \Monolog\Handler\StreamHandler('php://stdout'), Level::Debug);
    }

    public function log(string $message, array $context = []): void
    {
        $this->logger->log(Level::Debug, $message, $context);
    }
}
