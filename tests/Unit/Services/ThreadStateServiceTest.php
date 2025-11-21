<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services;

use Atlas\Nexus\Enums\AiMemoryOwnerType;
use Atlas\Nexus\Enums\AiMessageContentType;
use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Integrations\Prism\Tools\MemoryTool;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiPrompt;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Models\AiTool;
use Atlas\Nexus\Services\Models\AiAssistantToolService;
use Atlas\Nexus\Services\Threads\ThreadStateService;
use Atlas\Nexus\Tests\Fixtures\StubTool;
use Atlas\Nexus\Tests\TestCase;

/**
 * Class ThreadStateServiceTest
 *
 * Validates thread state aggregation for prompts, messages, memories, and tools.
 */
class ThreadStateServiceTest extends TestCase
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

    public function test_it_builds_thread_state_with_contextual_resources(): void
    {
        $assistant = AiAssistant::factory()->create(['slug' => 'state-assistant']);
        $prompt = AiPrompt::factory()->create([
            'assistant_id' => $assistant->id,
            'version' => 1,
            'system_prompt' => 'Stay helpful.',
        ]);

        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'prompt_id' => $prompt->id,
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'role' => AiMessageRole::USER->value,
            'sequence' => 1,
            'content_type' => AiMessageContentType::TEXT->value,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'role' => AiMessageRole::ASSISTANT->value,
            'sequence' => 2,
            'status' => AiMessageStatus::PROCESSING->value,
        ]);

        $memory = AiMemory::factory()->create([
            'assistant_id' => $assistant->id,
            'thread_id' => $thread->id,
            'owner_type' => AiMemoryOwnerType::USER->value,
            'owner_id' => $thread->user_id,
            'kind' => 'fact',
            'content' => 'User prefers morning updates.',
        ]);

        $tool = AiTool::factory()->create([
            'slug' => 'calendar_lookup',
            'handler_class' => StubTool::class,
        ]);

        $this->app->make(AiAssistantToolService::class)->create([
            'assistant_id' => $assistant->id,
            'tool_id' => $tool->id,
            'config' => [],
        ]);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);

        $toolSlugs = $state->tools->pluck('slug')->all();

        $this->assertSame($prompt->id, $state->prompt?->id);
        $this->assertCount(1, $state->messages);
        $this->assertTrue($state->memories->contains('id', $memory->id));
        $this->assertTrue(in_array($tool->slug, $toolSlugs, true));
        $this->assertTrue(in_array(MemoryTool::SLUG, $toolSlugs, true), 'Available slugs: '.implode(', ', $toolSlugs));
    }

    public function test_it_can_exclude_memory_tool_when_requested(): void
    {
        $assistant = AiAssistant::factory()->create(['slug' => 'state-assistant-disabled']);
        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
        ]);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread, false);

        $this->assertFalse($state->tools->contains('slug', MemoryTool::SLUG));
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
