<?php

/** @var \EK\Bootstrap $server */
$bootstrap = require_once __DIR__ . '/src/init.php';

$cliApplication = new \Symfony\Component\Console\Application();
$cliApplication->register('server')
    ->setDescription('Start the HTTP server')
    ->addOption('host', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The host to listen on', '0.0.0.0')
    ->addOption('port', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The port to listen on', 9501)
    ->addOption('external-address', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The external address to use', '')
    ->addOption('dial-home', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Dial home')
    ->addOption('user-agent', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The user agent to use', 'EVE-KILL ESI Proxy/1.0')
    ->addOption('skip304', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Skip 304 responses')
    ->addOption('rate-limit', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The rate limit to use', 500)
    ->addOption('wait-for-esi-error-reset', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Wait for ESI error reset')
    // Redis
    ->addOption('redis-host', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The Redis host to use', '127.0.0.1')
    ->addOption('redis-port', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The Redis port to use', 6379)
    ->addOption('redis-password', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The Redis password to use', '')
    ->addOption('redis-database', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The Redis database to use', 0)
    // Server
    ->addOption('workers', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The number of workers to use', 4)
    ->setCode(function (\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output) use ($bootstrap) {

        $container = $bootstrap->getContainer();
        $server = $container->get(\EK\Server\Server::class);

        $options = [
            'host' => $input->getOption('host'),
            'port' => $input->getOption('port'),
            'externalAddress' => $input->getOption('external-address'),
            'dialHome' => $input->getOption('dial-home'),
            'userAgent' => $input->getOption('user-agent'),
            'skip304' => $input->getOption('skip304'),
            'rateLimit' => $input->getOption('rate-limit'),
            'waitForEsiErrorReset' => $input->getOption('wait-for-esi-error-reset'),
            // Redis
            'redisHost' => $input->getOption('redis-host'),
            'redisPort' => $input->getOption('redis-port'),
            'redisPassword' => $input->getOption('redis-password'),
            'redisDatabase' => $input->getOption('redis-database'),
            // Server
            'workers' => $input->getOption('workers')
        ];

        $server->setOptions($options);
        $server->run();
    });

$cliApplication->setDefaultCommand('server', true);
$cliApplication->run();