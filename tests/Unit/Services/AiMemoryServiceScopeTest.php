<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services;

use Atlas\Nexus\Enums\AiMemoryOwnerType;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Models\AiMemoryService;
use Atlas\Nexus\Tests\TestCase;

class AiMemoryServiceScopeTest extends TestCase
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

    public function test_it_lists_memories_for_thread_and_assistant(): void
    {
        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
            'user_id' => 777,
        ]);

        /** @var AiMemory $memory */
        $memory = AiMemory::factory()->create([
            'assistant_key' => $thread->assistant_key,
            'thread_id' => $thread->id,
            'owner_type' => AiMemoryOwnerType::USER->value,
            'owner_id' => $thread->user_id,
            'kind' => 'fact',
            'content' => 'Prefers SMS alerts.',
        ]);

        AiMemory::factory()->create([
            'assistant_key' => 'secondary-assistant',
            'owner_type' => AiMemoryOwnerType::ASSISTANT->value,
            'owner_id' => 123,
            'kind' => 'fact',
            'content' => 'Other assistant memory.',
        ]);

        $service = $this->app->make(AiMemoryService::class);
        $assistant = $this->assistant('general-assistant');

        $memories = $service->listForThread($assistant, $thread);

        $this->assertCount(1, $memories);
        $this->assertTrue($memories->contains('id', $memory->id));
    }

    public function test_it_removes_memory_for_thread_scope(): void
    {
        $service = $this->app->make(AiMemoryService::class);
        $assistant = $this->assistant('general-assistant');
        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        /** @var AiMemory $memory */
        $memory = AiMemory::factory()->create([
            'assistant_key' => $thread->assistant_key,
            'thread_id' => $thread->id,
            'owner_type' => AiMemoryOwnerType::USER->value,
            'owner_id' => $thread->user_id,
        ]);

        $removed = $service->removeForThread($assistant, $thread, $memory->id);

        $this->assertTrue($removed);
        $this->assertSoftDeleted('ai_memories', ['id' => $memory->id]);
    }

    private function assistant(string $key): \Atlas\Nexus\Support\Assistants\ResolvedAssistant
    {
        return $this->app->make(\Atlas\Nexus\Services\Assistants\AssistantRegistry::class)->require($key);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
