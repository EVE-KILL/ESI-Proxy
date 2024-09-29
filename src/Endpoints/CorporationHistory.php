<?php

namespace EK\Endpoints;

use EK\Api\Endpoints;

class CorporationHistory extends Endpoints
{
    public array $routes = [
        '/{version:latest|dev|v1}/corporations/{id}/history[/]' => ['GET'],
    ];

    public int $hardRateLimit = 10;
}
