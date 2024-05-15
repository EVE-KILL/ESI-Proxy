<?php

namespace EK;

use Composer\Autoload\ClassLoader;
use EK\Cache\Cache;
use EK\Logger\Logger;
use EK\Server\Server;
use League\Container\Container;
use League\Container\ReflectionContainer;

class Bootstrap
{
    protected array $options = [];
    public function __construct(
        protected ClassLoader $autoloader,
        protected ?Container $container = null
    ) {
        $this->buildContainer();
    }

    protected function buildContainer(): void
    {
        $this->container = $this->container ?? new Container();

        // Register the reflection container
        $this->container->delegate(
            new ReflectionContainer(true)
        );

        // Add the autoloader
        $this->container->add(ClassLoader::class, $this->autoloader)
            ->setShared(true);

        // Add the container to itself
        $this->container->add(Container::class, $this->container)
            ->setShared(true);
    }

    public function getContainer(): Container
    {
        return $this->container;
    }
}
