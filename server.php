<?php

/** @var \EK\Bootstrap $server */

use Dotenv\Dotenv;
use EK\Server\RoadRunner;

$bootstrap = require_once __DIR__ . '/src/init.php';

// load env using dotenv
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

$container = $bootstrap->getContainer();

$roadRunner = $container->get(RoadRunner::class);
$roadRunner->run();
