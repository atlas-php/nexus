<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Tools;

use Atlas\Nexus\Contracts\ThreadStateAwareTool;
use Atlas\Nexus\Integrations\Prism\Tools\AbstractTool;
use Atlas\Nexus\Integrations\Prism\Tools\ToolParameter;
use Atlas\Nexus\Integrations\Prism\Tools\ToolResponse;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Models\AiToolRun;
use Atlas\Nexus\Services\Agents\ResolvedAgent;
use Atlas\Nexus\Services\Threads\Data\ThreadState;
use Atlas\Nexus\Services\Tools\ToolRunLogger;
use Atlas\Nexus\Tests\Fixtures\Agents\PrimaryAgentDefinition;
use Atlas\Nexus\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;
use Prism\Prism\Schema\StringSchema;
use RuntimeException;

use function collect;

/**
 * Class AbstractToolTest
 *
 * Ensures Nexus tools map cleanly to Prism tool definitions and normalize arguments.
 */
class AbstractToolTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

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

    public function test_it_logs_tool_run_lifecycle_when_thread_state_is_present(): void
    {
        $state = $this->makeThreadState();
        /** @var ToolRunLogger&MockInterface $logger */
        $logger = Mockery::mock(ToolRunLogger::class);
        $run = new AiToolRun;

        /** @var \Mockery\Expectation $startExpectation */
        $startExpectation = $logger->shouldReceive('start');
        $startExpectation->withArgs(['search_tool', $state, 42, 0, ['query' => 'widgets']])->andReturn($run);

        /** @var \Mockery\Expectation $completeExpectation */
        $completeExpectation = $logger->shouldReceive('complete');
        $completeExpectation->withArgs([$run, 'logged-result']);

        $tool = new class extends AbstractTool implements ThreadStateAwareTool
        {
            protected ?ThreadState $state = null;

            public function name(): string
            {
                return 'Search Tool';
            }

            public function description(): string
            {
                return 'Logs tool runs';
            }

            public function parameters(): array
            {
                return [
                    new ToolParameter(new StringSchema('query', 'Query term')),
                ];
            }

            public function handle(array $arguments): ToolResponse
            {
                return $this->output('done '.$arguments['query'], ['result' => 'logged-result']);
            }

            public function setThreadState(ThreadState $state): void
            {
                $this->state = $state;
            }
        };

        $tool->setToolRunLogger($logger);
        $tool->setToolKey('search_tool');
        $tool->setAssistantMessageId(42);
        $tool->setThreadState($state);

        $prismTool = $tool->toPrismTool();

        $this->assertSame('done widgets', $prismTool->handle('widgets'));
    }

    public function test_it_logs_failures_when_handle_throws(): void
    {
        $state = $this->makeThreadState();
        /** @var ToolRunLogger&MockInterface $logger */
        $logger = Mockery::mock(ToolRunLogger::class);
        $run = new AiToolRun;

        /** @var \Mockery\Expectation $startExpectation */
        $startExpectation = $logger->shouldReceive('start');
        $startExpectation->andReturn($run);

        /** @var \Mockery\Expectation $failExpectation */
        $failExpectation = $logger->shouldReceive('fail');
        $failExpectation->withArgs([$run, 'boom']);

        $tool = new class extends AbstractTool implements ThreadStateAwareTool
        {
            protected ?ThreadState $state = null;

            public bool $shouldThrow = true;

            public function name(): string
            {
                return 'Failing Tool';
            }

            public function description(): string
            {
                return 'Throws to test failures';
            }

            public function parameters(): array
            {
                return [
                    new ToolParameter(new StringSchema('query', 'Query term')),
                ];
            }

            public function handle(array $arguments): ToolResponse
            {
                throw new RuntimeException('boom');
            }

            public function setThreadState(ThreadState $state): void
            {
                $this->state = $state;
            }
        };

        $tool->setToolRunLogger($logger);
        $tool->setToolKey('failing_tool');
        $tool->setAssistantMessageId(7);
        $tool->setThreadState($state);

        $prismTool = $tool->toPrismTool();

        $this->assertSame('Tool execution error: boom', $prismTool->handle('widgets'));
    }

    public function test_it_normalizes_argument_payloads(): void
    {
        $tool = new class extends AbstractTool
        {
            public function name(): string
            {
                return 'Normalizer';
            }

            public function description(): string
            {
                return 'Normalizes arguments';
            }

            public function parameters(): array
            {
                return [
                    new ToolParameter(new StringSchema('first', 'First value')),
                    new ToolParameter(new StringSchema('second', 'Second value')),
                ];
            }

            public function handle(array $arguments): ToolResponse
            {
                return $this->output('ok');
            }

            /**
             * @param  array<int|string, mixed>  $arguments
             * @return array<string, mixed>
             */
            public function exposedNormalize(array $arguments): array
            {
                return $this->normalizeArguments($arguments);
            }

            /**
             * @param  array<int|string, mixed>  $arguments
             * @return array<string, mixed>
             */
            public function exposedStringify(array $arguments): array
            {
                return $this->stringifyKeys($arguments);
            }

            public function exposedNextCallIndex(): int
            {
                return $this->nextCallIndex();
            }
        };

        $this->assertSame(
            ['first' => 'a', 'second' => 'b'],
            $tool->exposedNormalize([['a', 'b']])
        );

        $this->assertSame(
            ['first' => 'c', 'second' => 'd'],
            $tool->exposedNormalize(['c', 'd'])
        );

        $this->assertSame(
            ['first' => 'x', 'second' => 'y'],
            $tool->exposedNormalize(['first' => 'x', 'second' => 'y'])
        );

        $this->assertSame(
            ['0' => 'value', 'custom' => true],
            $tool->exposedStringify([0 => 'value', 'custom' => true])
        );

        $this->assertSame(0, $tool->exposedNextCallIndex());
        $this->assertSame(1, $tool->exposedNextCallIndex());
    }

    private function makeThreadState(): ThreadState
    {
        $assistant = new ResolvedAgent(new PrimaryAgentDefinition);
        $thread = AiThread::factory()->make(['assistant_key' => 'general-assistant']);

        return new ThreadState(
            $thread,
            $assistant,
            null,
            collect(),
            collect(),
            collect(),
            null,
            collect()
        );
    }
}
