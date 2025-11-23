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
        $this->assertSoftDeleted($memory);

        $this->expectException(RuntimeException::class);
        $service->removeForThread($assistant, $thread, $memory->id + 100);
    }

    public function test_it_updates_memories_and_respects_filters(): void
    {
        $assistant = AiAssistant::factory()->create(['slug' => 'memory-updates']);
        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'user_id' => 777,
        ]);

        $service = $this->app->make(AiMemoryService::class);

        $older = $service->saveForThread(
            $assistant,
            $thread,
            'fact',
            'Older memory'
        );

        $recent = $service->saveForThread(
            $assistant,
            $thread,
            'preference',
            'Recent memory'
        );

        $service->updateForThread($assistant, $thread, $older->id, content: 'Older updated', kind: 'constraint');

        $ordered = $service->listForThread($assistant, $thread);
        $this->assertSame([$recent->id, $older->id], $ordered->pluck('id')->all());

        $filtered = $service->listForThread($assistant, $thread, memoryIds: [$older->id]);
        $this->assertCount(1, $filtered);
        $single = $filtered->first();
        $this->assertInstanceOf(AiMemory::class, $single);
        $this->assertSame('constraint', $single->kind);
        $this->assertSame('Older updated', $single->content);

        $this->expectException(RuntimeException::class);
        $service->updateForThread($assistant, $thread, 99999);
    }

    public function test_user_memories_are_accessible_across_threads(): void
    {
        $assistant = AiAssistant::factory()->create(['slug' => 'memory-cross-thread']);
        $threadOne = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'user_id' => 1111,
        ]);

        $threadTwo = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'user_id' => 1111,
        ]);

        $service = $this->app->make(AiMemoryService::class);

        $memory = $service->saveForThread(
            $assistant,
            $threadOne,
            'fact',
            'User detail for all threads',
            threadScoped: true
        );

        $memories = $service->listForThread($assistant, $threadTwo);
        $this->assertTrue($memories->contains('id', $memory->id));
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
