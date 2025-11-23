<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services;

use Atlas\Nexus\Enums\AiMemoryOwnerType;
use Atlas\Nexus\Enums\AiMessageContentType;
use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Integrations\Prism\Tools\MemoryTool;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Threads\ThreadStateService;
use Atlas\Nexus\Services\Tools\ToolRegistry;
use Atlas\Nexus\Support\Tools\ToolDefinition;
use Atlas\Nexus\Tests\Fixtures\Assistants\PrimaryAssistantDefinition;
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

        $this->assertTrue(in_array(MemoryTool::KEY, $toolKeys, true));
        $this->assertTrue(in_array('calendar_lookup', $toolKeys, true));
    }

    public function test_it_can_disable_memory_tool(): void
    {
        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread, false);

        $this->assertFalse($state->tools->contains(fn ($definition) => $definition->key() === MemoryTool::KEY));
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

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
