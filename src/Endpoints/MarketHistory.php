<?php

namespace EK\Endpoints;

use EK\Api\Endpoints;
use OpenSwoole\Core\Psr\ServerRequest as Request;
use Slim\Psr7\Response;

class MarketHistory extends Endpoints
{
    public array $routes = [
        '/latest/markets/{id}/history' => ['GET'],
        '/dev/markets/{id}/history' => ['GET'],
        '/v1/markets/{id}/history' => ['GET'],
    ];

    public int $hardRateLimit = 5;
}