<?php

declare(strict_types=1);

namespace Atlas\Nexus\Providers;

use Atlas\Core\Providers\PackageServiceProvider;
use Atlas\Nexus\NexusManager;
use Atlas\Nexus\Services\Models\AiAssistantService;
use Atlas\Nexus\Services\Models\AiAssistantToolService;
use Atlas\Nexus\Services\Models\AiMemoryService;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Models\AiPromptService;
use Atlas\Nexus\Services\Models\AiThreadService;
use Atlas\Nexus\Services\Models\AiToolRunService;
use Atlas\Nexus\Services\Models\AiToolService;
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

        $this->app->singleton(AiAssistantService::class);
        $this->app->singleton(AiPromptService::class);
        $this->app->singleton(AiThreadService::class);
        $this->app->singleton(AiMessageService::class);
        $this->app->singleton(AiToolService::class);
        $this->app->singleton(AiAssistantToolService::class);
        $this->app->singleton(AiToolRunService::class);
        $this->app->singleton(AiMemoryService::class);
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

            $this->publishes([
                $this->packageDatabasePath('migrations') => database_path('migrations'),
            ], $this->tags()->migrations());

            $this->notifyPendingInstallSteps(
                'Atlas Nexus',
                'atlas-nexus.php',
                $this->tags()->config(),
                '*ai_assistants*',
                $this->tags()->migrations()
            );
        }
    }

    protected function packageSlug(): string
    {
        return 'atlas nexus';
    }
}
