<?php

namespace EK\EVEKILL;

use EK\Server\Server;
use GuzzleHttp\Client;

class DialHomeDevice
{
    protected Client $client;
    public function __construct(
        protected Server $server
    ) {
       $this->client = new Client([
           'base_uri' => '127.0.0.1:9201'
       ]);
    }
    public function callHome(string $host, int $port, string $externalAddress) {
        $result = $this->client->post('/api/proxy/add', [
            'json' => [
                'id' => $this->generateName(),
                'url' => $externalAddress,
                'owner' => $this->server->getOptions()['owner']
            ]
        ]);

        return json_decode($result->getBody()->getContents(), true);
    }

    private function generateName(): string
    {
        // Store the name in a file on disk
        $namePath = '/tmp/esi-proxy.name';

        if (file_exists($namePath)) {
            return file_get_contents($namePath);
        }

        $name = bin2hex(random_bytes(16));
        file_put_contents($namePath, $name);

        return $name;
    }
}