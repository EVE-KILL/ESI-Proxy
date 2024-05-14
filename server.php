<?php

/** @var \EK\HttpServer $server */
$server = require_once __DIR__ . '/src/init.php';

$cliApplication = new \Symfony\Component\Console\Application();
$cliApplication->register('server')
    ->setDescription('Start the HTTP server')
    ->addOption('host', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The host to listen on', '127.0.0.1')
    ->addOption('port', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The port to listen on', 9501)
    ->addOption('external-address', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The external address to use', '')
    ->setCode(function (\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output) use ($server) {
        $host = $input->getOption('host');
        $port = $input->getOption('port');
        $externalAddress = $input->getOption('external-address');

        $server->run($host, $port, $externalAddress);
    });

$cliApplication->setDefaultCommand('server', true);
$cliApplication->run();