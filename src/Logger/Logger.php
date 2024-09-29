<?php

namespace EK\Logger;

use Spiral\Goridge\RPC\RPC;
use Spiral\RoadRunner\Logger as RoadRunnerLogger;

class Logger
{
    protected $logger;

    public function __construct()
    {
        $rpc = RPC::create('tcp://127.0.0.1:6001');
        $this->logger = new RoadRunnerLogger($rpc);
    }

    public function log(string $message, array $context = []): void
    {
        $this->logger->log($message, json_encode($context));
    }
}
