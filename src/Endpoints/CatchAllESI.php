<?php

namespace EK\Endpoints;

use EK\Api\Endpoints;

class CatchAllESI extends Endpoints
{
    // The higher this is, the later it's added to the list of routes
    public int $priority = 99999; // This should be the very last to be added
    public array $routes = [
        '/{version:latest|dev|v1|v2|v3|v4|v5|v6|v7}/{routes:.+}' => ['GET', 'POST']
    ];
}