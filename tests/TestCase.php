<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests;

use Atlas\Core\Testing\PackageTestCase;
use Atlas\Nexus\Providers\AtlasNexusServiceProvider;
use Atlas\Nexus\Services\Agents\AgentRegistry;
use Atlas\Nexus\Services\Threads\Hooks\ThreadHookRegistry;
use Atlas\Nexus\Tests\Fixtures\Agents\PrimaryAgentDefinition;
use Atlas\Nexus\Tests\Fixtures\Agents\SecondaryAgentDefinition;
use Atlas\Nexus\Tests\Fixtures\Agents\ThreadSummaryAgentDefinition;
use Prism\Prism\PrismServiceProvider;

/**
 * Class TestCase
 *
 * Boots Orchestra Testbench with the Atlas Nexus service provider for package feature coverage.
 * PRD Reference: Atlas Nexus Foundation Setup — Testing harness requirements.
 *
 * @property \Illuminate\Foundation\Application $app
 */
abstract class TestCase extends PackageTestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function getPackageProviders($app): array
    {
        return [
            AtlasNexusServiceProvider::class,
            PrismServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        PrimaryAgentDefinition::resetConfig();
        SecondaryAgentDefinition::resetConfig();
        ThreadSummaryAgentDefinition::resetConfig();

        if ($this->shouldUseAgentFixtures()) {
            $agents = [
                PrimaryAgentDefinition::class,
                SecondaryAgentDefinition::class,
                ThreadSummaryAgentDefinition::class,
            ];

            config()->set('atlas-nexus.agents', $agents);
        }

        $this->app->make(AgentRegistry::class)->refresh();
        $this->app->make(ThreadHookRegistry::class)->refresh();
    }

    protected function shouldUseAgentFixtures(): bool
    {
        return true;
    }
}
