<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services;

use Atlas\Nexus\Enums\AiMessageContentType;
use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Enums\AiThreadType;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Threads\ThreadStateService;
use Atlas\Nexus\Services\Tools\ToolDefinition;
use Atlas\Nexus\Services\Tools\ToolRegistry;
use Atlas\Nexus\Tests\Fixtures\Assistants\PrimaryAssistantDefinition;
use Atlas\Nexus\Tests\Fixtures\Assistants\ThreadSummaryAssistantDefinition;
use Atlas\Nexus\Tests\Fixtures\StubTool;
use Atlas\Nexus\Tests\TestCase;

class ThreadStateServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(ToolRegistry::class)->register(new ToolDefinition('calendar_lookup', StubTool::class));

        $this->loadPackageMigrations($this->migrationPath());
        $this->runPendingCommand('migrate:fresh', [
            '--path' => $this->migrationPath(),
            '--realpath' => true,
        ])->run();
    }

    public function test_it_builds_thread_state_with_messages_memories_and_tools(): void
    {
        PrimaryAssistantDefinition::updateConfig([
            'tools' => ['calendar_lookup'],
            'system_prompt' => 'Stay helpful.',
        ]);

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        AiMemory::factory()->create([
            'assistant_key' => $thread->assistant_key,
            'thread_id' => $thread->id,
            'user_id' => $thread->user_id,
            'content' => 'User prefers morning updates.',
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::USER->value,
            'sequence' => 1,
            'content_type' => AiMessageContentType::TEXT->value,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);

        $this->assertNotNull($state->systemPrompt);
        $this->assertCount(1, $state->messages);
        $this->assertCount(1, $state->memories);
        $firstMemory = $state->memories->first();
        $this->assertInstanceOf(AiMemory::class, $firstMemory);
        $this->assertSame('User prefers morning updates.', $firstMemory->content);
        $toolKeys = $state->tools->map(fn ($definition) => $definition->key())->all();

        $this->assertTrue(in_array('calendar_lookup', $toolKeys, true));
    }

    public function test_it_excludes_tool_when_not_enabled(): void
    {
        PrimaryAssistantDefinition::updateConfig([
            'tools' => [],
        ]);

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);

        $this->assertFalse($state->tools->contains(fn ($definition) => $definition->key() === 'calendar_lookup'));
    }

    public function test_it_returns_no_tools_when_assistant_has_none(): void
    {
        PrimaryAssistantDefinition::updateConfig([
            'tools' => [],
        ]);

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);

        $this->assertCount(0, $state->tools);
    }

    public function test_it_applies_provider_tool_configuration_from_assistant(): void
    {
        PrimaryAssistantDefinition::updateConfig([
            'provider_tools' => [
                'file_search' => ['vector_store_ids' => ['vs_123', '']],
            ],
        ]);

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);

        $this->assertCount(1, $state->providerTools);

        $definition = $state->providerTools->first();

        $this->assertInstanceOf(\Atlas\Nexus\Services\Tools\ProviderToolDefinition::class, $definition);
        $this->assertSame('file_search', $definition->key());
        $this->assertSame(['vector_store_ids' => ['vs_123']], $definition->options());
    }

    public function test_it_returns_no_provider_tools_when_assistant_has_none(): void
    {
        PrimaryAssistantDefinition::updateConfig([
            'provider_tools' => [],
        ]);

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);

        $this->assertCount(0, $state->providerTools);
    }

    public function test_thread_manager_prompt_variables_use_parent_thread_when_available(): void
    {
        ThreadSummaryAssistantDefinition::updateConfig([
            'system_prompt' => 'Summary for {THREAD.ID}: {THREAD.TITLE}',
        ]);

        /** @var AiThread $parent */
        $parent = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
            'type' => AiThreadType::USER->value,
            'title' => 'Ship the quarterly report',
        ]);

        /** @var AiThread $summaryThread */
        $summaryThread = AiThread::factory()->create([
            'assistant_key' => 'thread-summary-assistant',
            'type' => AiThreadType::TOOL->value,
            'parent_thread_id' => $parent->id,
            'user_id' => $parent->user_id,
            'group_id' => $parent->group_id,
        ]);

        $freshSummary = $summaryThread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshSummary);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshSummary);

        $this->assertSame(
            'Summary for '.$parent->getKey().': '.$parent->title,
            $state->systemPrompt
        );
    }

    public function test_it_snapshots_prompt_on_first_state_build(): void
    {
        PrimaryAssistantDefinition::updateConfig([
            'system_prompt' => 'Original instructions.',
        ]);

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        $service = $this->app->make(ThreadStateService::class);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);
        $initialState = $service->forThread($freshThread);

        $this->assertSame('Original instructions.', $initialState->prompt);

        $snapshot = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $snapshot);
        $this->assertSame('Original instructions.', $snapshot->prompt_snapshot);

        PrimaryAssistantDefinition::updateConfig([
            'system_prompt' => 'Updated instructions mid-thread.',
        ]);

        $refreshedThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $refreshedThread);
        $subsequentState = $service->forThread($refreshedThread);

        $this->assertSame('Original instructions.', $subsequentState->prompt);
    }

    public function test_it_can_disable_prompt_snapshotting_with_config(): void
    {
        config()->set('atlas-nexus.threads.snapshot_prompts', false);

        try {
            PrimaryAssistantDefinition::updateConfig([
                'system_prompt' => 'Unlocked instructions.',
            ]);

            /** @var AiThread $thread */
            $thread = AiThread::factory()->create([
                'assistant_key' => 'general-assistant',
            ]);

            $service = $this->app->make(ThreadStateService::class);

            $initialThread = $thread->fresh();
            $this->assertInstanceOf(AiThread::class, $initialThread);
            $initialState = $service->forThread($initialThread);

            $this->assertSame('Unlocked instructions.', $initialState->prompt);
            $this->assertNull($thread->fresh()?->prompt_snapshot);

            PrimaryAssistantDefinition::updateConfig([
                'system_prompt' => 'New unlocked instructions.',
            ]);

            $refreshedThread = $thread->fresh();
            $this->assertInstanceOf(AiThread::class, $refreshedThread);
            $updatedState = $service->forThread($refreshedThread);

            $this->assertSame('New unlocked instructions.', $updatedState->prompt);
            $this->assertNull($thread->fresh()?->prompt_snapshot);
        } finally {
            config()->set('atlas-nexus.threads.snapshot_prompts', true);
        }
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
