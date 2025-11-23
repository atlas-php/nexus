<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Jobs;

use Atlas\Nexus\Enums\AiMemoryOwnerType;
use Atlas\Nexus\Enums\AiMessageContentType;
use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Enums\AiToolRunStatus;
use Atlas\Nexus\Integrations\Prism\Tools\MemoryTool;
use Atlas\Nexus\Jobs\RunAssistantResponseJob;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiPrompt;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Models\AiToolRun;
use Atlas\Nexus\Services\Seeders\NexusSeederService;
use Atlas\Nexus\Tests\Fixtures\StubTool;
use Atlas\Nexus\Tests\Fixtures\ThrowingTextRequestFactory;
use Atlas\Nexus\Tests\TestCase;
use Illuminate\Support\Facades\App;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;

use function collect;

/**
 * Class RunAssistantResponseJobTest
 *
 * Validates assistant response generation, tool logging, and failure handling.
 */
class RunAssistantResponseJobTest extends TestCase
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

    public function test_it_updates_message_and_records_tool_runs(): void
    {
        config()->set('atlas-nexus.tools.registry', [
            'calendar_lookup' => StubTool::class,
        ]);

        $assistant = AiAssistant::factory()->create([
            'slug' => 'job-assistant',
            'default_model' => 'gpt-4o',
            'tools' => ['calendar_lookup'],
        ]);
        $prompt = AiPrompt::factory()->create([
            'assistant_id' => $assistant->id,
            'system_prompt' => 'Assist politely.',
        ]);
        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'prompt_id' => $prompt->id,
            'user_id' => 1,
        ]);

        $memory = AiMemory::factory()->create([
            'assistant_id' => $assistant->id,
            'thread_id' => $thread->id,
            'owner_type' => AiMemoryOwnerType::USER->value,
            'owner_id' => $thread->user_id,
            'kind' => 'preference',
            'content' => 'User prefers concise replies.',
        ]);

        $userMessage = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'role' => AiMessageRole::USER->value,
            'sequence' => 1,
            'content_type' => AiMessageContentType::TEXT->value,
            'status' => AiMessageStatus::COMPLETED->value,
            'content' => 'Hello!',
        ]);

        $assistantMessage = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'role' => AiMessageRole::ASSISTANT->value,
            'sequence' => 2,
            'status' => AiMessageStatus::PROCESSING->value,
            'content' => '',
        ]);

        /** @var \Illuminate\Support\Collection<int, \Prism\Prism\Contracts\Message> $messages */
        $messages = collect([
            new UserMessage('Hello!'),
            new AssistantMessage('Here is your update.'),
        ]);

        $response = new TextResponse(
            steps: collect([]),
            text: 'Here is your update.',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [
                new ToolResult('call-1', 'calendar_lookup', ['date' => '2025-01-01'], ['events' => 2]),
            ],
            usage: new Usage(10, 20),
            meta: new Meta('res-123', 'gpt-4o-mini'),
            messages: $messages,
            additionalContent: [],
        );

        Prism::fake([$response]);

        RunAssistantResponseJob::dispatchSync($assistantMessage->id);

        $assistantMessage->refresh();

        $metadata = $assistantMessage->metadata ?? [];

        $this->assertTrue($assistantMessage->status === AiMessageStatus::COMPLETED);
        $this->assertSame('Here is your update.', $assistantMessage->content);
        $this->assertSame('res-123', $assistantMessage->provider_response_id);
        $this->assertSame(10, $assistantMessage->tokens_in);
        $this->assertSame(20, $assistantMessage->tokens_out);
        $this->assertIsArray($assistantMessage->raw_response);
        $this->assertSame('Here is your update.', $assistantMessage->raw_response['text']);
        $this->assertSame('calendar_lookup', $assistantMessage->raw_response['tool_results'][0]['tool_name'] ?? null);
        $this->assertArrayHasKey('memory_ids', $metadata);
        $this->assertArrayHasKey('tool_run_ids', $metadata);
        $this->assertContains($memory->id, $metadata['memory_ids']);
        $this->assertNotEmpty($metadata['tool_run_ids']);

        $toolRun = AiToolRun::first();
        $this->assertInstanceOf(AiToolRun::class, $toolRun);
        $this->assertSame(AiToolRunStatus::SUCCEEDED->value, $toolRun->status->value);
        $this->assertSame($assistantMessage->id, $toolRun->assistant_message_id);
        $this->assertSame('calendar_lookup', $toolRun->tool_key);
        $this->assertSame(['events' => 2], $toolRun->response_output);
        $this->assertSame(['date' => '2025-01-01'], $toolRun->input_args);
    }

    public function test_it_marks_message_as_failed_when_prism_errors(): void
    {
        $assistant = AiAssistant::factory()->create(['slug' => 'job-failure']);
        $prompt = AiPrompt::factory()->create([
            'assistant_id' => $assistant->id,
        ]);
        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'prompt_id' => $prompt->id,
            'user_id' => 1,
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'role' => AiMessageRole::USER->value,
            'sequence' => 1,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        $assistantMessage = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'role' => AiMessageRole::ASSISTANT->value,
            'sequence' => 2,
            'status' => AiMessageStatus::PROCESSING->value,
        ]);

        App::instance(\Atlas\Nexus\Integrations\Prism\TextRequestFactory::class, new ThrowingTextRequestFactory);

        $this->expectException(RuntimeException::class);

        try {
            RunAssistantResponseJob::dispatchSync($assistantMessage->id);
        } finally {
            $assistantMessage->refresh();
            $this->assertTrue($assistantMessage->status === AiMessageStatus::FAILED);
            $this->assertSame('Simulated Prism failure', $assistantMessage->failed_reason);
        }
    }

    public function test_it_records_memory_tool_runs_when_enabled(): void
    {
        $assistant = AiAssistant::factory()->create([
            'slug' => 'job-memory-tool',
            'default_model' => 'gpt-4o',
        ]);
        $this->app->make(NexusSeederService::class)->run();
        $prompt = AiPrompt::factory()->create([
            'assistant_id' => $assistant->id,
        ]);
        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'prompt_id' => $prompt->id,
            'user_id' => 77,
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'role' => AiMessageRole::USER->value,
            'sequence' => 1,
            'status' => AiMessageStatus::COMPLETED->value,
            'content' => 'Store a memory.',
        ]);

        $assistantMessage = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'role' => AiMessageRole::ASSISTANT->value,
            'sequence' => 2,
            'status' => AiMessageStatus::PROCESSING->value,
            'content' => '',
        ]);

        /** @var \Illuminate\Support\Collection<int, \Prism\Prism\Contracts\Message> $messages */
        $messages = collect([
            new UserMessage('Store a memory.'),
            new AssistantMessage('Memory stored.'),
        ]);

        $response = new TextResponse(
            steps: collect([]),
            text: 'Memory stored.',
            finishReason: FinishReason::Stop,
            toolCalls: [
                new \Prism\Prism\ValueObjects\ToolCall('call-1', MemoryTool::KEY, ['action' => 'add'], 'result-1'),
            ],
            toolResults: [
                new ToolResult('call-1', MemoryTool::KEY, ['action' => 'add'], ['success' => true]),
            ],
            usage: new Usage(5, 10),
            meta: new Meta('res-555', 'gpt-4o'),
            messages: $messages,
            additionalContent: [],
        );

        Prism::fake([$response]);

        RunAssistantResponseJob::dispatchSync($assistantMessage->id);

        $toolRun = AiToolRun::query()
            ->where('tool_key', MemoryTool::KEY)
            ->first();

        $this->assertInstanceOf(AiToolRun::class, $toolRun);
        $this->assertSame(AiToolRunStatus::SUCCEEDED->value, $toolRun->status->value);
        $this->assertSame(['action' => 'add'], $toolRun->input_args);
        $this->assertSame(['success' => true], $toolRun->response_output);
        $this->assertSame($assistantMessage->id, $toolRun->assistant_message_id);
    }

    public function test_it_has_single_attempt_and_twenty_minute_timeout(): void
    {
        $job = new RunAssistantResponseJob(123);

        $this->assertSame(1, $job->tries);
        $this->assertSame(1_200, $job->timeout);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
