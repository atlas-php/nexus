<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services\Threads;

use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Threads\ThreadMemoryService;
use Atlas\Nexus\Tests\TestCase;
use Illuminate\Support\Carbon;

class ThreadMemoryServiceTest extends TestCase
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

    public function test_it_appends_unique_memories(): void
    {
        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        $service = $this->app->make(ThreadMemoryService::class);

        AiMemory::factory()->create([
            'assistant_key' => $thread->assistant_key,
            'thread_id' => $thread->id,
            'user_id' => $thread->user_id,
            'content' => 'Existing fact',
        ]);

        $service->appendMemories($thread, [
            [
                'content' => 'Existing fact',
            ],
            [
                'content' => 'Enjoys winter hikes',
            ],
        ]);

        $memories = AiMemory::query()->where('thread_id', $thread->id)->orderBy('id')->get();
        $this->assertCount(2, $memories);

        /** @var AiMemory $firstMemory */
        $firstMemory = $memories->first();
        $this->assertInstanceOf(AiMemory::class, $firstMemory);
        $this->assertSame($thread->id, $firstMemory->thread_id);

        /** @var AiMemory $lastMemory */
        $lastMemory = $memories->last();
        $this->assertInstanceOf(AiMemory::class, $lastMemory);
        $this->assertSame('Enjoys winter hikes', $lastMemory->content);
        $this->assertSame(3, $lastMemory->importance);
    }

    public function test_it_merges_user_memories(): void
    {
        $userId = 77;

        AiMemory::factory()->create([
            'assistant_key' => 'general-assistant',
            'user_id' => $userId,
            'content' => 'First memory',
        ]);

        AiMemory::factory()->create([
            'assistant_key' => 'general-assistant',
            'user_id' => $userId,
            'content' => 'Second insight',
        ]);

        AiMemory::factory()->create([
            'assistant_key' => 'general-assistant',
            'user_id' => $userId + 1,
            'content' => 'Other user memory',
        ]);

        $service = $this->app->make(ThreadMemoryService::class);

        $memories = $service->userMemories($userId, 'general-assistant');

        $this->assertCount(2, $memories);
        $this->assertTrue($memories->contains(fn (AiMemory $memory): bool => $memory->content === 'First memory'));
        $this->assertTrue($memories->contains(fn (AiMemory $memory): bool => $memory->content === 'Second insight'));
    }

    public function test_memories_sort_by_decay(): void
    {
        $userId = 101;

        Carbon::setTestNow(Carbon::parse('2025-01-10 12:00:00'));
        config()->set('atlas-nexus.memory.decay_days', 2);

        AiMemory::factory()->create([
            'assistant_key' => 'general-assistant',
            'user_id' => $userId,
            'content' => 'Older memory',
            'importance' => 5,
            'created_at' => Carbon::now()->subDays(6),
        ]);

        AiMemory::factory()->create([
            'assistant_key' => 'general-assistant',
            'user_id' => $userId,
            'content' => 'Recent memory',
            'importance' => 2,
            'created_at' => Carbon::now()->subDay(),
        ]);

        $service = $this->app->make(ThreadMemoryService::class);

        $memories = $service->userMemories($userId, 'general-assistant');

        $this->assertSame('Recent memory', $memories->first()?->content);
        $this->assertSame('Older memory', $memories->last()?->content);
    }

    public function test_remove_memories_by_content(): void
    {
        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        AiMemory::factory()->create([
            'assistant_key' => $thread->assistant_key,
            'user_id' => $thread->user_id,
            'content' => 'Remove this',
        ]);

        AiMemory::factory()->create([
            'assistant_key' => $thread->assistant_key,
            'user_id' => $thread->user_id,
            'content' => 'Keep this',
        ]);

        $service = $this->app->make(ThreadMemoryService::class);

        $removed = $service->removeMemories($thread, ['Remove this']);

        $this->assertSame(1, $removed);
        $this->assertSame('Keep this', AiMemory::query()->first()?->content);
    }

    public function test_it_orders_memories_by_importance(): void
    {
        $userId = 88;

        AiMemory::factory()->create([
            'assistant_key' => 'general-assistant',
            'user_id' => $userId,
            'content' => 'Low priority',
            'importance' => 1,
        ]);

        AiMemory::factory()->create([
            'assistant_key' => 'general-assistant',
            'user_id' => $userId,
            'content' => 'High priority',
            'importance' => 5,
        ]);

        $service = $this->app->make(ThreadMemoryService::class);

        $ordered = $service->userMemories($userId, 'general-assistant');

        $this->assertSame('High priority', $ordered->first()?->content);
        $this->assertSame('Low priority', $ordered->last()?->content);
    }

    public function test_it_removes_memories_by_content(): void
    {
        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        AiMemory::factory()->create([
            'assistant_key' => $thread->assistant_key,
            'user_id' => $thread->user_id,
            'content' => 'Remove this memory',
        ]);

        AiMemory::factory()->create([
            'assistant_key' => $thread->assistant_key,
            'user_id' => $thread->user_id,
            'content' => 'Keep this memory',
        ]);

        $service = $this->app->make(ThreadMemoryService::class);

        $removed = $service->removeMemories($thread, ['Remove this memory']);

        $this->assertSame(1, $removed);
        $this->assertSame(1, AiMemory::query()->count());
        $this->assertSame('Keep this memory', AiMemory::query()->first()?->content);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../../database/migrations';
    }
}
