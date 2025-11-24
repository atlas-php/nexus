<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services\Threads;

use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Threads\ThreadMemoryService;
use Atlas\Nexus\Tests\TestCase;

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
            'assistant_id' => $thread->assistant_key,
            'thread_id' => $thread->id,
            'user_id' => $thread->user_id,
            'content' => 'Existing fact',
            'source_message_ids' => [1],
        ]);

        $service->appendMemories($thread, [
            [
                'content' => 'Existing fact',
                'source_message_ids' => [2],
            ],
            [
                'content' => 'Enjoys winter hikes',
                'source_message_ids' => ['3', 'abc'],
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
        $this->assertSame([3], $lastMemory->source_message_ids);
    }

    public function test_it_merges_user_memories(): void
    {
        $userId = 77;

        AiMemory::factory()->create([
            'assistant_id' => 'general-assistant',
            'user_id' => $userId,
            'content' => 'First memory',
        ]);

        AiMemory::factory()->create([
            'assistant_id' => 'general-assistant',
            'user_id' => $userId,
            'content' => 'Second insight',
        ]);

        AiMemory::factory()->create([
            'assistant_id' => 'general-assistant',
            'user_id' => $userId + 1,
            'content' => 'Other user memory',
        ]);

        $service = $this->app->make(ThreadMemoryService::class);

        $memories = $service->userMemories($userId, 'general-assistant');

        $this->assertCount(2, $memories);
        $this->assertTrue($memories->contains(fn (AiMemory $memory): bool => $memory->content === 'First memory'));
        $this->assertTrue($memories->contains(fn (AiMemory $memory): bool => $memory->content === 'Second insight'));
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../../database/migrations';
    }
}
