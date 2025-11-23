<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Integrations\Prism\Tools;

use Atlas\Nexus\Contracts\ThreadStateAwareTool;
use Atlas\Nexus\Enums\AiThreadStatus;
use Atlas\Nexus\Enums\AiThreadType;
use Atlas\Nexus\Enums\AiToolRunStatus;
use Atlas\Nexus\Integrations\Prism\Tools\AbstractTool;
use Atlas\Nexus\Integrations\Prism\Tools\ToolParameter;
use Atlas\Nexus\Integrations\Prism\Tools\ToolResponse;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiAssistantPrompt;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Models\AiToolRun;
use Atlas\Nexus\Services\Tools\ToolRunLogger;
use Atlas\Nexus\Support\Chat\ThreadState;
use Atlas\Nexus\Tests\TestCase;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\StringSchema;
use RuntimeException;

/**
 * Class AbstractToolTest
 *
 * Verifies common tool lifecycle behavior such as run logging when handlers throw exceptions.
 */
class AbstractToolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadPackageMigrations($this->migrationPath());
        $this->runPendingCommand('migrate:fresh', [
            '--path' => $this->migrationPath(),
            '--realpath' => true,
        ])->run();
    }

    public function test_it_marks_runs_as_failed_when_tool_throws(): void
    {
        $state = $this->createState();
        $logger = $this->app->make(ToolRunLogger::class);

        $tool = $this->app->make(FailingTool::class);
        $tool->setThreadState($state);
        $tool->setToolRunLogger($logger);
        $tool->setToolKey('failing_tool');
        $tool->setAssistantMessageId(1);

        $prismTool = $tool->toPrismTool()->as('failing_tool');
        $result = $prismTool->handle();

        $this->assertStringContainsString('Tool execution error: Forced failure', $result);

        /** @var AiToolRun|null $run */
        $run = AiToolRun::query()->first();
        $this->assertInstanceOf(AiToolRun::class, $run);
        $this->assertSame(AiToolRunStatus::FAILED, $run->status);
        $this->assertSame('Forced failure', $run->error_message);
        $this->assertNotNull($run->finished_at);
    }

    public function test_it_normalizes_named_arguments_without_numeric_indexes(): void
    {
        $tool = $this->app->make(NamedArgumentTool::class);
        $prismTool = $tool->toPrismTool()->as('named_argument_tool');

        $result = $prismTool->handle(thread_ids: [1, 2, 3]);

        $this->assertSame('Captured arguments.', $result);
        $this->assertSame(['thread_ids' => [1, 2, 3]], $tool->arguments());
    }

    protected function createState(): ThreadState
    {
        $assistant = AiAssistant::factory()->create([
            'slug' => 'failing-tool',
            'tools' => ['failing_tool'],
        ]);

        $prompt = AiAssistantPrompt::factory()->create([
            'assistant_id' => $assistant->id,
        ]);

        $assistant->update(['current_prompt_id' => $prompt->id]);

        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'user_id' => 1,
            'type' => AiThreadType::USER->value,
            'status' => AiThreadStatus::OPEN->value,
        ]);

        return new ThreadState(
            $thread,
            $assistant,
            $prompt,
            collect(),
            collect(),
            collect(),
            null,
            null,
            collect()
        );
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../../../database/migrations';
    }
}

/**
 * Class FailingTool
 *
 * Test-only tool that always throws to confirm failed runs are recorded.
 */
class FailingTool extends AbstractTool implements ThreadStateAwareTool
{
    protected ?ThreadState $state = null;

    public function setThreadState(ThreadState $state): void
    {
        $this->state = $state;
    }

    public function name(): string
    {
        return 'Failing Tool';
    }

    public function description(): string
    {
        return 'Throws for testing failure logging.';
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function handle(array $arguments): ToolResponse
    {
        throw new RuntimeException('Forced failure');
    }
}

/**
 * Class NamedArgumentTool
 *
 * Captures normalized arguments to ensure named tool calls are parsed without numeric indexes.
 */
class NamedArgumentTool extends AbstractTool
{
    /** @var array<string, mixed> */
    private array $capturedArguments = [];

    /**
     * @return array<int, \Atlas\Nexus\Integrations\Prism\Tools\ToolParameter>
     */
    public function parameters(): array
    {
        return [
            new ToolParameter(
                new ArraySchema(
                    'thread_ids',
                    'Thread identifiers.',
                    new StringSchema('thread_id', 'Thread identifier.', true),
                    true
                ),
                true
            ),
        ];
    }

    public function name(): string
    {
        return 'Named Argument Tool';
    }

    public function description(): string
    {
        return 'Captures normalized arguments.';
    }

    /**
     * @return array<string, mixed>
     */
    public function arguments(): array
    {
        return $this->capturedArguments;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function handle(array $arguments): ToolResponse
    {
        $this->capturedArguments = $arguments;

        return $this->output('Captured arguments.', ['result' => $arguments]);
    }
}
