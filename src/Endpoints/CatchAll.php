<?php

namespace EK\Endpoints;

use EK\Api\Endpoints;

class CatchAll extends Endpoints
{
    // The higher this is, the later it's added to the list of routes
    public int $priority = 99999; // This should be the very last to be added
    public array $routes = [
        '/{routes:.+}' => ['GET']
    ];
}