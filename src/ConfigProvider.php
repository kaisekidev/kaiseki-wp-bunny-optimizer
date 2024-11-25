<?php

declare(strict_types=1);

namespace Kaiseki\WordPress\BunnyOptimizer;

final class ConfigProvider
{
    /**
     * @return array<mixed>
     */
    public function __invoke(): array
    {
        return [
            'bunny_optimizer' => [
                'feature_notice' => 'wp-bunny-optimizer',
            ],
            'hook' => [
                'provider' => [
                    BunnyOptimizer::class,
                ],
            ],
            'dependencies' => [
                'aliases' => [],
                'factories' => [
                    BunnyOptimizer::class => BunnyOpt::class,
                ],
            ],
        ];
    }
}
