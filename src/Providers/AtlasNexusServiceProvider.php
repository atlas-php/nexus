<?php

declare(strict_types=1);

namespace Atlas\Nexus\Providers;

use Atlas\Core\Providers\PackageServiceProvider;
use Atlas\Nexus\Integrations\OpenAI\OpenAiRateLimitClient;
use Atlas\Nexus\Integrations\Prism\TextRequestFactory;
use Atlas\Nexus\NexusManager;
use Atlas\Nexus\Services\Assistants\AssistantRegistry;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Models\AiThreadService;
use Atlas\Nexus\Services\Models\AiToolRunService;
use Atlas\Nexus\Services\NexusPurgeService;
use Atlas\Nexus\Services\Prompts\PromptVariableRegistry;
use Atlas\Nexus\Services\Prompts\PromptVariableService;
use Atlas\Nexus\Services\Threads\AssistantResponseService;
use Atlas\Nexus\Services\Threads\ThreadMessageService;
use Atlas\Nexus\Services\Threads\ThreadMemoryExtractionService;
use Atlas\Nexus\Services\Threads\ThreadMemoryService;
use Atlas\Nexus\Services\Threads\ThreadStateService;
use Atlas\Nexus\Services\Tools\ProviderToolRegistry;
use Atlas\Nexus\Services\Tools\ToolRegistry;
use Atlas\Nexus\Services\Tools\ToolRunLogger;

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
        $this->app->singleton(OpenAiRateLimitClient::class);

        $this->app->singleton(NexusManager::class, static fn ($app): NexusManager => new NexusManager(
            $app->make(TextRequestFactory::class),
        ));

        $this->app->alias(NexusManager::class, 'atlas-nexus.manager');
        $this->app->alias(TextRequestFactory::class, 'atlas-nexus.text-factory');

        $this->app->singleton(AssistantRegistry::class);
        $this->app->singleton(AiThreadService::class);
        $this->app->singleton(AiMessageService::class);
        $this->app->singleton(AiToolRunService::class);
        $this->app->singleton(PromptVariableRegistry::class);
        $this->app->singleton(PromptVariableService::class);
        $this->app->singleton(ThreadMemoryService::class);
        $this->app->singleton(ThreadMemoryExtractionService::class);
        $this->app->singleton(ProviderToolRegistry::class);
        $this->app->singleton(ToolRegistry::class);
        $this->app->singleton(ToolRunLogger::class);
        $this->app->singleton(AssistantResponseService::class);
        $this->app->singleton(ThreadStateService::class);
        $this->app->singleton(ThreadMessageService::class);
        $this->app->singleton(NexusPurgeService::class);
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
                '*create_ai_threads_table.php',
                $this->tags()->migrations()
            );

            $this->commands([
                \Atlas\Nexus\Console\Commands\NexusPurgeCommand::class,
            ]);
        }
    }

    protected function packageSlug(): string
    {
        return 'atlas nexus';
    }
}
