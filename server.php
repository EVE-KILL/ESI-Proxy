<?php

/** @var \EK\Bootstrap $server */

use EK\Server\Server;

$bootstrap = require_once __DIR__ . '/src/init.php';

$cliApplication = new \Symfony\Component\Console\Application();
$numberOfCPUs = (int) (intval(trim(shell_exec('nproc'))));
$cliApplication->register('server')
    ->setDescription('Start the HTTP server')
    ->addOption('host', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The host to listen on', '0.0.0.0')
    ->addOption('port', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The port to listen on', 9501)
    ->addOption('external-address', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The external address to use', '')
    ->addOption('dial-home', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Dial home')
    ->addOption('owner', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The owner to use', '')
    // Redis
    ->addOption('redis-host', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The Redis host to use', '127.0.0.1')
    ->addOption('redis-port', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The Redis port to use', 6379)
    ->addOption('redis-password', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The Redis password to use', '')
    ->addOption('redis-database', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The Redis database to use', 0)
    // Server
    ->addOption('workers', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The number of workers to use', $numberOfCPUs)
    ->setCode(function (\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output) use ($bootstrap) {

        $container = $bootstrap->getContainer();
        /** @var Server $server */
        $server = $container->get(Server::class);

        $options = [
            'host' => $input->getOption('host'),
            'port' => $input->getOption('port'),
            'externalAddress' => $input->getOption('external-address'),
            'dialHome' => $input->getOption('dial-home'),
            'owner' => $input->getOption('owner'),
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
