<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Providers;

use Atlas\Nexus\NexusManager;
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
    }
}
