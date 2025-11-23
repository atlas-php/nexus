<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services\Tools;

use Atlas\Nexus\Integrations\Prism\Tools\MemoryTool;
use Atlas\Nexus\Integrations\Prism\Tools\ThreadFetcherTool;
use Atlas\Nexus\Integrations\Prism\Tools\ThreadSearchTool;
use Atlas\Nexus\Integrations\Prism\Tools\ThreadUpdaterTool;
use Atlas\Nexus\Integrations\Prism\Tools\WebSearchTool;
use Atlas\Nexus\Services\Tools\ToolRegistry;
use Atlas\Nexus\Tests\TestCase;

/**
 * Class ToolRegistryTest
 *
 * Ensures built-in tool definitions are available for UI listing and configuration.
 */
class ToolRegistryTest extends TestCase
{
    public function test_it_lists_registered_tools(): void
    {
        $registry = $this->app->make(ToolRegistry::class);

        $available = $registry->available();

        $this->assertArrayHasKey(MemoryTool::KEY, $available);
        $this->assertArrayHasKey(WebSearchTool::KEY, $available);
        $this->assertArrayHasKey(ThreadSearchTool::KEY, $available);
        $this->assertArrayHasKey(ThreadFetcherTool::KEY, $available);
        $this->assertArrayHasKey(ThreadUpdaterTool::KEY, $available);
    }
}
