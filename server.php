<?php

/** @var \EK\Bootstrap $server */
$server = require_once __DIR__ . '/src/init.php';

$cliApplication = new \Symfony\Component\Console\Application();
$cliApplication->register('server')
    ->setDescription('Start the HTTP server')
    ->addOption('host', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The host to listen on', '0.0.0.0')
    ->addOption('port', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The port to listen on', 9501)
    ->addOption('external-address', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The external address to use', '')
    ->addOption('dial-home', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Dial home')
    ->addOption('user-agent', null, \Symfony\Component\Console\Input\InputOption::VALUE_REQUIRED, 'The user agent to use', 'EVE-KILL ESI Proxy/1.0')
    ->addOption('skip304', null, \Symfony\Component\Console\Input\InputOption::VALUE_NONE, 'Skip 304 responses')
    ->setCode(function (\Symfony\Component\Console\Input\InputInterface $input, \Symfony\Component\Console\Output\OutputInterface $output) use ($server) {

        $options = [
            'host' => $input->getOption('host'),
            'port' => $input->getOption('port'),
            'externalAddress' => $input->getOption('external-address'),
            'dialHome' => $input->getOption('dial-home'),
            'userAgent' => $input->getOption('user-agent'),
            'skip304' => $input->getOption('skip304'),
        ];

        $server->run($options);
    });

$cliApplication->setDefaultCommand('server', true);
$cliApplication->run();