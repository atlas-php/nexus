<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services;

use Atlas\Nexus\Enums\AiMemoryOwnerType;
use Atlas\Nexus\Enums\AiMessageContentType;
use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Enums\AiThreadType;
use Atlas\Nexus\Integrations\Prism\Tools\MemoryTool;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Threads\ThreadStateService;
use Atlas\Nexus\Services\Tools\ToolRegistry;
use Atlas\Nexus\Support\Tools\ToolDefinition;
use Atlas\Nexus\Tests\Fixtures\Assistants\PrimaryAssistantDefinition;
use Atlas\Nexus\Tests\Fixtures\Assistants\ThreadManagerAssistantDefinition;
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
            'tools' => ['memory', 'calendar_lookup'],
            'system_prompt' => 'Stay helpful.',
        ]);

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::USER->value,
            'sequence' => 1,
            'content_type' => AiMessageContentType::TEXT->value,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        AiMemory::factory()->create([
            'assistant_key' => $thread->assistant_key,
            'thread_id' => $thread->id,
            'owner_type' => AiMemoryOwnerType::USER->value,
            'owner_id' => $thread->user_id,
            'kind' => 'fact',
            'content' => 'User prefers morning updates.',
        ]);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);

        $this->assertNotNull($state->systemPrompt);
        $this->assertCount(1, $state->messages);
        $this->assertCount(1, $state->memories);
        $toolKeys = $state->tools->map(fn ($definition) => $definition->key())->all();

        $this->assertTrue(in_array('calendar_lookup', $toolKeys, true));
        $this->assertTrue(in_array(MemoryTool::KEY, $toolKeys, true));
    }

    public function test_it_excludes_memory_tool_when_not_enabled(): void
    {
        PrimaryAssistantDefinition::updateConfig([
            'tools' => ['calendar_lookup'],
        ]);

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);

        $this->assertFalse($state->tools->contains(fn ($definition) => $definition->key() === MemoryTool::KEY));
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

        $this->assertInstanceOf(\Atlas\Nexus\Support\Tools\ProviderToolDefinition::class, $definition);
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
        ThreadManagerAssistantDefinition::updateConfig([
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
            'assistant_key' => 'thread-manager',
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

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
