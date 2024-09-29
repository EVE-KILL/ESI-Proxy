<?php

namespace EK\Endpoints;

use EK\Api\Endpoints;

class CharacterHistory extends Endpoints
{
    public array $routes = [
        '/{version:latest|dev|v1}/characters/{id}/history[/]' => ['GET'],
    ];

    public int $hardRateLimit = 10;
}
