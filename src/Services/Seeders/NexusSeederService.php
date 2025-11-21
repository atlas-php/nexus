<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Seeders;

use Atlas\Nexus\Contracts\NexusSeeder;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Class NexusSeederService
 *
 * Coordinates execution of built-in and consumer-provided seeders to set up Nexus defaults.
 */
class NexusSeederService
{
    /**
     * @var array<int, class-string<NexusSeeder>>
     */
    protected array $extraSeeders = [];

    public function __construct(
        private readonly ConfigRepository $config,
        private readonly Container $container
    ) {}

    /**
     * Register additional seeders at runtime.
     *
     * @param  array<int, string>  $seeders
     */
    public function extend(array $seeders): void
    {
        foreach ($seeders as $seeder) {
            if (! class_exists($seeder) || ! is_subclass_of($seeder, NexusSeeder::class)) {
                continue;
            }

            $this->extraSeeders[] = $seeder;
        }
    }

    /**
     * Execute registered seeders.
     *
     * @param  array<int, class-string<NexusSeeder>>|null  $seeders
     */
    public function run(?array $seeders = null): void
    {
        $resolvedSeeders = $seeders ?? $this->config->get('atlas-nexus.seeders', []);
        $resolvedSeeders = array_merge($resolvedSeeders, $this->extraSeeders);
        $resolvedSeeders = array_values(array_unique($resolvedSeeders));

        foreach ($resolvedSeeders as $seederClass) {
            $seeder = $this->container->make($seederClass);

            if (! $seeder instanceof NexusSeeder) {
                throw new InvalidArgumentException(sprintf(
                    'Seeder [%s] must implement %s.',
                    $seederClass,
                    NexusSeeder::class
                ));
            }

            $seeder->seed();
        }
    }
}
