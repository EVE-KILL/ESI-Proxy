# ESI Proxy

ESI Proxy is an ESI proxy for EVE-Online.

It acts as an intermediary between your application and the ESI (https://esi.evetech.net)

## Development Setup

To run ESI Proxy locally for development, you need the following things installed:

- php8.3
- openswoole
- redis

To start the webserver run the following command:

```bash
php server.php
```

This command will start the server in it's default mode, listening on 0.0.0.0 port 9501.

To change this behavior you can pass --host and --port options to the command, like so:

```bash
php server.php --host=your_host --port=your_port
```

## Docker Container

ESI Proxy is also available as a Docker container for easy deployment. The Docker image is hosted on GitHub Container Registry. You can run the container using the following command:

```bash
docker run -p 9501:9501 ghcr.io/eve-kill/esi-proxy:latest
```

This command will pull the latest version of the ESI Proxy image and run it. The `-p` option is used to map the container's port 9501 to your machine's port 9501.

To change the host, port or any of the other multitude of options, you can run it like:

```bash
docker run -p 9501:9501 ghcr.io/eve-kill/esi-proxy:latest --host=your_host --port=your_port
```

## Options available at runtime
- --host=your_host
- --port=your_port
- --workers=number_of_workers
- --redis-host=your_redis_host
- --redis-port=your_redis_port
- --redis-password=your_redis_password
- --redis-database=your_redis_database
- --user-agent=your_user_agent

The following are for certain edge cases, --skip304 is to skip returning 304s in exchange for only returning 200. --wait-for-esi-error-reset is to pause further execution until the error reset timer has passed.
- --skip304
- --wait-for-esi-error-reset

The following are only for if you turn on dial-home, at which point it will dial home to https://esi.eve-kill.com

And tell it exists on a certain domain, at which point it will be part of the network of proxies powering the EVE-KILL ESI Proxy

- --dial-home
- --external-address=your_external_address

## Contributing

Contributions to the ESI Proxy project are welcome.

## License

ESI Proxy is open-source software licensed under the MIT license.