<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services\Agents;

use Atlas\Nexus\Services\Agents\AgentRegistry;
use Atlas\Nexus\Services\Agents\ResolvedAgent;
use Atlas\Nexus\Tests\Fixtures\Agents\PrimaryAgentDefinition;
use Atlas\Nexus\Tests\Fixtures\Agents\SecondaryAgentDefinition;
use Atlas\Nexus\Tests\TestCase;
use RuntimeException;

/**
 * Class AgentRegistryTest
 *
 * Ensures the agent registry exposes configured agents and refreshes when config changes.
 */
class AgentRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas-nexus.agents', [
            PrimaryAgentDefinition::class,
            SecondaryAgentDefinition::class,
        ]);
    }

    public function test_it_returns_all_resolved_agents(): void
    {
        /** @var AgentRegistry $registry */
        $registry = $this->app->make(AgentRegistry::class);
        $registry->refresh();

        $all = $registry->all();

        $this->assertCount(2, $all);
        $this->assertContainsOnlyInstancesOf(ResolvedAgent::class, $all);
        $this->assertArrayHasKey('general-assistant', $all);
        $this->assertArrayHasKey('secondary-assistant', $all);
    }

    public function test_it_returns_agent_keys(): void
    {
        /** @var AgentRegistry $registry */
        $registry = $this->app->make(AgentRegistry::class);
        $registry->refresh();

        $this->assertSame(
            ['general-assistant', 'secondary-assistant'],
            $registry->keys()
        );
    }

    public function test_find_returns_agent_case_insensitive(): void
    {
        /** @var AgentRegistry $registry */
        $registry = $this->app->make(AgentRegistry::class);
        $registry->refresh();

        $assistant = $registry->find('GENERAL-ASSISTANT');

        $this->assertInstanceOf(ResolvedAgent::class, $assistant);
        $this->assertSame('general-assistant', $assistant->key());
    }

    public function test_require_throws_for_missing_assistant(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Agent definition [missing] is not registered.');

        /** @var AgentRegistry $registry */
        $registry = $this->app->make(AgentRegistry::class);
        $registry->refresh();
        $registry->require('missing');
    }

    public function test_refresh_reloads_from_config(): void
    {
        /** @var AgentRegistry $registry */
        $registry = $this->app->make(AgentRegistry::class);
        $registry->refresh();

        $this->assertSame(['general-assistant', 'secondary-assistant'], $registry->keys());

        config()->set('atlas-nexus.agents', [
            SecondaryAgentDefinition::class,
        ]);

        $registry->refresh();

        $this->assertSame(['secondary-assistant'], $registry->keys());
    }
}
