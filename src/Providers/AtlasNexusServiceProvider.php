<?php

declare(strict_types=1);

namespace Atlas\Nexus\Providers;

use Atlas\Core\Providers\PackageServiceProvider;
use Atlas\Nexus\NexusManager;
use Atlas\Nexus\Text\TextRequestFactory;

/**
 * Class AtlasNexusServiceProvider
 *
 * Boots the Atlas Nexus package by exposing configuration and binding the manager singleton.
 * PRD Reference: Atlas Nexus Foundation Setup — Package registration.
 */
class AtlasNexusServiceProvider extends PackageServiceProvider
{
    protected string $packageBasePath = __DIR__.'/../..';

    /**
     * Register the package configuration and service bindings.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            $this->packageConfigPath('atlas-nexus.php'),
            'atlas-nexus'
        );

        $this->app->singleton(TextRequestFactory::class, static fn (): TextRequestFactory => new TextRequestFactory);

        $this->app->singleton(NexusManager::class, static fn ($app): NexusManager => new NexusManager(
            $app['config'],
            $app->make(TextRequestFactory::class),
        ));

        $this->app->alias(NexusManager::class, 'atlas-nexus.manager');
        $this->app->alias(TextRequestFactory::class, 'atlas-nexus.text-factory');
    }

    /**
     * Publish configuration and surface installation hints for new consumers.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                $this->packageConfigPath('atlas-nexus.php') => config_path('atlas-nexus.php'),
            ], $this->tags()->config());

            $this->notifyPendingInstallSteps(
                'Atlas Nexus',
                'atlas-nexus.php',
                $this->tags()->config()
            );
        }
    }

    protected function packageSlug(): string
    {
        return 'atlas nexus';
    }
}
