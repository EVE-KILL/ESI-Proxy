<?php

$autoloader = dirname(__DIR__, 1) . '/vendor/autoload.php';
if (!file_exists($autoloader)) {
    throw new \RuntimeException('Autoloader not found, please run composer install');
}

require_once $autoloader;

return new EK\Bootstrap();
