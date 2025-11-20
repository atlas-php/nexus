<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Tools;

use Atlas\Nexus\Tests\TestCase;
use Atlas\Nexus\Tools\AbstractTool;
use Atlas\Nexus\Tools\ToolParameter;
use Atlas\Nexus\Tools\ToolResponse;
use Prism\Prism\Schema\StringSchema;

/**
 * Class AbstractToolTest
 *
 * Ensures Nexus tools map cleanly to Prism tool definitions and normalize arguments.
 */
class AbstractToolTest extends TestCase
{
    public function test_it_builds_prism_tool_and_handles_arguments(): void
    {
        $tool = new class extends AbstractTool
        {
            public function name(): string
            {
                return 'search';
            }

            public function description(): string
            {
                return 'Perform a search';
            }

            public function parameters(): array
            {
                return [
                    new ToolParameter(new StringSchema('query', 'Query term')),
                ];
            }

            public function handle(array $arguments): ToolResponse
            {
                return $this->output('result for '.$arguments['query']);
            }
        };

        $prismTool = $tool->toPrismTool();

        $this->assertSame('search', $prismTool->name());
        $this->assertArrayHasKey('query', $prismTool->parameters());
        $this->assertSame('result for widgets', $prismTool->handle('widgets'));
    }
}
