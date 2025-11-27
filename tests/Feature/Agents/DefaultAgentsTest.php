<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Feature\Agents;

use Atlas\Nexus\Services\Agents\AgentRegistry;
use Atlas\Nexus\Services\Agents\Definitions\GeneralAgent;
use Atlas\Nexus\Services\Agents\Definitions\HumanAgent;
use Atlas\Nexus\Services\Agents\Definitions\MemoryAgent;
use Atlas\Nexus\Services\Agents\Definitions\ThreadSummaryAgent;
use Atlas\Nexus\Tests\TestCase;

/**
 * Class DefaultAgentsTest
 *
 * Ensures the package registers its built-in agent definitions so consumers have defaults without custom code.
 */
class DefaultAgentsTest extends TestCase
{
    protected function shouldUseAgentFixtures(): bool
    {
        return false;
    }

    public function test_it_registers_built_in_agents_by_default(): void
    {
        $expected = [
            GeneralAgent::class,
            HumanAgent::class,
            ThreadSummaryAgent::class,
            MemoryAgent::class,
        ];

        $this->assertSame($expected, config('atlas-nexus.agents'));

        $registry = $this->app->make(AgentRegistry::class);

        $this->assertSame(
            ['general-assistant', 'human-assistant', 'thread-summary-assistant', 'memory-assistant'],
            $registry->keys()
        );
    }
}
