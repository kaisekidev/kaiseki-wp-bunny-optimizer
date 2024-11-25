<?php

declare(strict_types=1);

namespace Kaiseki\WordPress\BunnyOptimizer;

use Kaiseki\Config\Config;
use Psr\Container\ContainerInterface;

final class BunnyOptimizerFactory
{
    public function __invoke(ContainerInterface $container): BunnyOptimizer
    {
        $config = Config::fromContainer($container);

        return new BunnyOptimizer();
    }
}
