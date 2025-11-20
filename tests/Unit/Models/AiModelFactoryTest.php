<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Models;

use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiAssistantTool;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiPrompt;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Models\AiTool;
use Atlas\Nexus\Models\AiToolRun;
use Atlas\Nexus\Tests\TestCase;
use Illuminate\Support\Carbon;

/**
 * Class AiModelFactoryTest
 *
 * Ensures all Nexus models and factories persist linked records and apply attribute casts correctly.
 * PRD Reference: Atlas Nexus Overview — Database schema section.
 */
class AiModelFactoryTest extends TestCase
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

    public function test_factories_create_linked_records_with_expected_casts(): void
    {
        /** @var AiAssistant $assistant */
        $assistant = AiAssistant::factory()->create([
            'metadata' => ['tier' => 'enterprise'],
        ]);

        /** @var AiPrompt $prompt */
        $prompt = AiPrompt::factory()->create([
            'assistant_id' => $assistant->id,
            'version' => 1,
            'variables_schema' => ['type' => 'object'],
            'is_active' => true,
        ]);

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'user_id' => 42,
            'type' => 'user',
            'prompt_id' => $prompt->id,
            'last_message_at' => Carbon::now()->subMinutes(5),
            'metadata' => ['channel' => 'web'],
            'status' => 'open',
        ]);

        /** @var AiMessage $message */
        $message = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'user_id' => $thread->user_id,
            'sequence' => 1,
            'content_type' => 'text',
            'role' => 'user',
            'tokens_in' => 10,
            'tokens_out' => 5,
            'metadata' => ['sentiment' => 'positive'],
        ]);

        /** @var AiTool $tool */
        $tool = AiTool::factory()->create([
            'schema' => ['type' => 'object', 'properties' => ['query' => ['type' => 'string']]],
            'is_active' => true,
        ]);

        /** @var AiAssistantTool $assistantTool */
        $assistantTool = AiAssistantTool::factory()->create([
            'assistant_id' => $assistant->id,
            'tool_id' => $tool->id,
            'config' => ['mode' => 'sync'],
        ]);

        /** @var AiToolRun $toolRun */
        $toolRun = AiToolRun::factory()->create([
            'tool_id' => $tool->id,
            'thread_id' => $thread->id,
            'assistant_message_id' => $message->id,
            'call_index' => 0,
            'input_args' => ['query' => 'demo'],
            'status' => 'succeeded',
            'response_output' => ['ok' => true],
            'metadata' => ['duration_ms' => 120],
            'error_message' => null,
            'started_at' => Carbon::now()->subSeconds(10),
            'finished_at' => Carbon::now(),
        ]);

        /** @var AiMemory $memory */
        $memory = AiMemory::factory()->create([
            'assistant_id' => $assistant->id,
            'thread_id' => $thread->id,
            'source_message_id' => $message->id,
            'source_tool_run_id' => $toolRun->id,
            'metadata' => ['context' => 'demo'],
        ]);

        $this->assertSame($assistant->id, $prompt->assistant_id);
        $this->assertSame(['type' => 'object'], $prompt->variables_schema);
        $this->assertTrue($prompt->is_active);

        $this->assertSame(['tier' => 'enterprise'], $assistant->metadata);

        $this->assertSame($assistant->id, $thread->assistant_id);
        $this->assertSame(42, $thread->user_id);
        $this->assertInstanceOf(Carbon::class, $thread->last_message_at);
        $this->assertSame(['channel' => 'web'], $thread->metadata);

        $this->assertSame($thread->id, $message->thread_id);
        $this->assertSame(1, $message->sequence);
        $this->assertSame('text', $message->content_type);
        $this->assertSame(['sentiment' => 'positive'], $message->metadata);

        $this->assertSame(['type' => 'object', 'properties' => ['query' => ['type' => 'string']]], $tool->schema);
        $this->assertTrue($tool->is_active);

        $this->assertSame('succeeded', $toolRun->status);
        $this->assertSame(['query' => 'demo'], $toolRun->input_args);
        $this->assertSame(['ok' => true], $toolRun->response_output);
        $this->assertNotNull($toolRun->started_at);
        $this->assertNotNull($toolRun->finished_at);
        $this->assertTrue($toolRun->finished_at->greaterThan($toolRun->started_at));

        $this->assertSame($assistant->id, $assistantTool->assistant_id);
        $this->assertSame($tool->id, $assistantTool->tool_id);
        $this->assertSame(['mode' => 'sync'], $assistantTool->config);

        $this->assertSame($assistant->id, $memory->assistant_id);
        $this->assertSame($thread->id, $memory->thread_id);
        $this->assertSame(['context' => 'demo'], $memory->metadata);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
