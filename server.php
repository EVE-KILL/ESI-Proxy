<?php

/** @var \EK\Bootstrap $server */

use Dotenv\Dotenv;
use EK\EVEKILL\DialHomeDevice;
use EK\Server\RoadRunner;

$bootstrap = require_once __DIR__ . '/src/init.php';

// load env using dotenv
if (file_exists(__DIR__ . '/.env')) {
    $dotenv = Dotenv::createImmutable(__DIR__);
    $dotenv->load();
}

// Dial Home
$dialHome = in_array($_ENV['DIAL_HOME'], ['true', true, 1, '1']);
if ($dialHome && $_ENV['EXTERNAL_ADDRESS'] !== '') {
    $dialHomeDevice = $container->get(DialHomeDevice::class);
    $response = $dialHomeDevice->callHome($_ENV['HOST'], $_ENV['PORT'], $_ENV['EXTERNAL_ADDRESS']);
    echo 'DialHomeDevice response: ' . json_encode($response) ?? 'Unknown error';
}

$container = $bootstrap->getContainer();

$roadRunner = $container->get(RoadRunner::class);
$roadRunner->run();
