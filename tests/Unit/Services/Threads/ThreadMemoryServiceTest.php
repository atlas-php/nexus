<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services\Threads;

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
            'memories' => [
                [
                    'content' => 'Existing fact',
                    'source_message_ids' => [1],
                    'created_at' => now()->subDay()->toAtomString(),
                ],
            ],
        ]);

        $service = $this->app->make(ThreadMemoryService::class);

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

        $updated = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $updated);
        $this->assertCount(2, $updated->memories);
        $lastMemory = $updated->memories[1];

        $this->assertSame('Enjoys winter hikes', $lastMemory['content']);
        $this->assertSame($thread->id, $updated->memories[0]['thread_id']);
        $this->assertSame($thread->id, $lastMemory['thread_id']);
        $this->assertSame([3], $lastMemory['source_message_ids']);
    }

    public function test_it_merges_user_memories(): void
    {
        $userId = 77;

        AiThread::factory()->create([
            'user_id' => $userId,
            'memories' => [
                ['content' => 'First memory', 'created_at' => now()->subDays(2)->toAtomString()],
            ],
        ]);

        AiThread::factory()->create([
            'user_id' => $userId,
            'memories' => [
                ['content' => 'Second insight', 'created_at' => now()->toAtomString()],
            ],
        ]);

        AiThread::factory()->create([
            'user_id' => $userId + 1,
            'memories' => [
                ['content' => 'Other user memory', 'created_at' => now()->toAtomString()],
            ],
        ]);

        $service = $this->app->make(ThreadMemoryService::class);

        $memories = $service->userMemories($userId);

        $this->assertCount(2, $memories);
        $this->assertTrue($memories->contains(fn (array $memory): bool => $memory['content'] === 'First memory'));
        $this->assertTrue($memories->contains(fn (array $memory): bool => $memory['content'] === 'Second insight'));
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../../database/migrations';
    }
}
