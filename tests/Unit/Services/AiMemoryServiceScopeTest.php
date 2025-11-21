<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services;

use Atlas\Nexus\Enums\AiMemoryOwnerType;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Models\AiMemoryService;
use Atlas\Nexus\Tests\TestCase;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Class AiMemoryServiceScopeTest
 *
 * Validates memory storage, retrieval, and removal while enforcing assistant and user scoping.
 */
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

    public function test_it_saves_and_filters_memories_by_scope_and_dates(): void
    {
        $assistant = AiAssistant::factory()->create(['slug' => 'memory-service']);
        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'user_id' => 120,
        ]);

        $service = $this->app->make(AiMemoryService::class);

        $threadMemory = $service->saveForThread(
            $assistant,
            $thread,
            'fact',
            'User prefers concise updates.',
            AiMemoryOwnerType::USER,
            ['topic' => 'preferences'],
            true
        );

        $assistantMemory = $service->saveForThread(
            $assistant,
            $thread,
            'note',
            'Assistant note',
            AiMemoryOwnerType::ASSISTANT,
            null,
            false
        );

        $olderMemory = $service->saveForThread(
            $assistant,
            $thread,
            'fact',
            'Old detail',
            AiMemoryOwnerType::USER
        );

        $olderMemory->update(['created_at' => Carbon::now()->subDays(5)]);

        AiMemory::factory()->create([
            'owner_type' => AiMemoryOwnerType::USER->value,
            'owner_id' => 999,
            'assistant_id' => $assistant->id,
            'thread_id' => null,
        ]);

        $allMemories = $service->listForThread($assistant, $thread);
        $recent = $service->listForThread($assistant, $thread, from: Carbon::now()->subDay());

        $this->assertTrue($allMemories->contains('id', $assistantMemory->id));
        $this->assertTrue($allMemories->contains('id', $threadMemory->id));
        $this->assertFalse($allMemories->contains('owner_id', 999));

        $this->assertTrue($recent->contains('id', $assistantMemory->id));
        $this->assertFalse($recent->contains('id', $olderMemory->id));
    }

    public function test_it_removes_only_accessible_memories(): void
    {
        $assistant = AiAssistant::factory()->create(['slug' => 'memory-removal']);
        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'user_id' => 345,
        ]);

        $service = $this->app->make(AiMemoryService::class);

        $memory = $service->saveForThread(
            $assistant,
            $thread,
            'summary',
            'Cleanup request',
            AiMemoryOwnerType::USER,
            threadScoped: true
        );

        AiMemory::factory()->create([
            'owner_type' => AiMemoryOwnerType::USER->value,
            'owner_id' => 999,
            'assistant_id' => $assistant->id,
            'thread_id' => null,
        ]);

        $this->assertTrue($service->removeForThread($assistant, $thread, $memory->id));
        $this->assertModelMissing($memory);

        $this->expectException(RuntimeException::class);
        $service->removeForThread($assistant, $thread, $memory->id + 100);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
