<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Providers;

use Atlas\Nexus\Integrations\Prism\TextRequestFactory;
use Atlas\Nexus\NexusManager;
use Atlas\Nexus\Services\Threads\AssistantResponseService;
use Atlas\Nexus\Services\Threads\ThreadMessageService;
use Atlas\Nexus\Services\Threads\ThreadStateService;
use Atlas\Nexus\Services\Tools\ToolRunLogger;
use Atlas\Nexus\Tests\TestCase;

/**
 * Class AtlasNexusServiceProviderTest
 *
 * Ensures the service provider exposes bindings and configuration defaults for consumers.
 * PRD Reference: Atlas Nexus Foundation Setup — Service provider contract.
 */
class AtlasNexusServiceProviderTest extends TestCase
{
    public function test_manager_binding_is_singleton_and_aliased(): void
    {
        $resolved = $this->app->make(NexusManager::class);

        $this->assertSame($resolved, $this->app->make(NexusManager::class));
        $this->assertSame($resolved, $this->app->make('atlas-nexus.manager'));
    }

    public function test_default_configuration_is_available(): void
    {
        $this->assertSame('default', config('atlas-nexus.default_pipeline'));
        $this->assertNull(config('atlas-nexus.responses.queue'));
    }

    public function test_text_request_factory_binding_is_available(): void
    {
        $resolved = $this->app->make(TextRequestFactory::class);

        $this->assertSame($resolved, $this->app->make('atlas-nexus.text-factory'));
    }

    public function test_thread_services_are_bound(): void
    {
        $this->assertInstanceOf(ThreadStateService::class, $this->app->make(ThreadStateService::class));
        $this->assertInstanceOf(ThreadMessageService::class, $this->app->make(ThreadMessageService::class));
        $this->assertInstanceOf(AssistantResponseService::class, $this->app->make(AssistantResponseService::class));
        $this->assertInstanceOf(ToolRunLogger::class, $this->app->make(ToolRunLogger::class));
    }
}
