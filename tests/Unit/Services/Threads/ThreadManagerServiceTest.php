<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services\Threads;

use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Threads\ThreadManagerService;
use Atlas\Nexus\Services\Threads\ThreadMemoryService;
use Atlas\Nexus\Services\Threads\ThreadStateService;
use Atlas\Nexus\Tests\TestCase;
use Illuminate\Support\Carbon;

class ThreadManagerServiceTest extends TestCase
{
    private ThreadManagerService $manager;

    private ThreadStateService $stateService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadPackageMigrations($this->migrationPath());
        $this->runPendingCommand('migrate:fresh', [
            '--path' => $this->migrationPath(),
            '--realpath' => true,
        ])->run();

        $this->manager = $this->app->make(ThreadManagerService::class);
        $this->stateService = $this->app->make(ThreadStateService::class);
    }

    public function test_fetch_context_summaries_limits_to_ten_threads(): void
    {
        /** @var AiThread $activeThread */
        $activeThread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
            'user_id' => 42,
            'last_message_at' => Carbon::now(),
        ]);

        AiMessage::factory()->create([
            'thread_id' => $activeThread->id,
            'assistant_key' => $activeThread->assistant_key,
            'user_id' => $activeThread->user_id,
            'role' => AiMessageRole::USER->value,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        $threadIdsByMinute = [];

        foreach (range(1, 11) as $minutes) {
            /** @var AiThread $thread */
            $thread = AiThread::factory()->create([
                'assistant_key' => 'general-assistant',
                'user_id' => $activeThread->user_id,
                'last_message_at' => Carbon::now()->subMinutes($minutes),
            ]);

            AiMessage::factory()->create([
                'thread_id' => $thread->id,
                'assistant_key' => $thread->assistant_key,
                'user_id' => $thread->user_id,
                'role' => AiMessageRole::USER->value,
                'status' => AiMessageStatus::COMPLETED->value,
                'content' => "message {$minutes}",
            ]);

            $threadIdsByMinute[$minutes] = $thread->id;
        }

        $state = $this->stateService->forThread($activeThread);
        $results = $this->manager->fetchContextSummaries($state);

        $this->assertCount(10, $results);
        $expectedOrder = array_map(
            fn (int $minute): int => $threadIdsByMinute[$minute],
            range(1, 10)
        );
        $this->assertSame($expectedOrder, array_column($results, 'id'));
    }

    public function test_fetch_context_summaries_matches_memories_and_messages(): void
    {
        /** @var AiThread $activeThread */
        $activeThread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
            'user_id' => 99,
            'last_message_at' => Carbon::now(),
        ]);

        AiMessage::factory()->create([
            'thread_id' => $activeThread->id,
            'assistant_key' => $activeThread->assistant_key,
            'user_id' => $activeThread->user_id,
            'role' => AiMessageRole::USER->value,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        /** @var AiThread $memoryThread */
        $memoryThread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
            'user_id' => $activeThread->user_id,
            'last_message_at' => Carbon::now()->subMinutes(1),
        ]);

        $now = Carbon::now();

        AiMemory::factory()->create([
            'assistant_key' => $memoryThread->assistant_key,
            'thread_id' => $memoryThread->id,
            'user_id' => $memoryThread->user_id,
            'content' => 'User loves skiing trips',
            'importance' => 5,
            'created_at' => $now->copy()->subMinutes(3),
            'updated_at' => $now->copy()->subMinutes(3),
        ]);

        AiMemory::factory()->create([
            'assistant_key' => $memoryThread->assistant_key,
            'thread_id' => $memoryThread->id,
            'user_id' => $memoryThread->user_id,
            'content' => 'Enjoys gardening',
            'importance' => 3,
            'created_at' => $now->copy()->subMinutes(1),
            'updated_at' => $now->copy()->subMinutes(1),
        ]);

        $threadMemories = $this->app->make(ThreadMemoryService::class)->memoriesForThread($memoryThread);
        $this->assertCount(2, $threadMemories);

        AiMessage::factory()->create([
            'thread_id' => $memoryThread->id,
            'assistant_key' => $memoryThread->assistant_key,
            'user_id' => $memoryThread->user_id,
            'role' => AiMessageRole::USER->value,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        /** @var AiThread $messageThread */
        $messageThread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
            'user_id' => $activeThread->user_id,
            'last_message_at' => Carbon::now()->subMinutes(2),
        ]);

        AiMessage::factory()->create([
            'thread_id' => $messageThread->id,
            'assistant_key' => $messageThread->assistant_key,
            'user_id' => $messageThread->user_id,
            'role' => AiMessageRole::USER->value,
            'status' => AiMessageStatus::COMPLETED->value,
            'content' => 'Discussed gardening projects and tools.',
        ]);

        $state = $this->stateService->forThread($activeThread);

        $memoryResults = $this->manager->fetchContextSummaries($state, ['skiing']);
        $this->assertCount(1, $memoryResults);
        $this->assertSame($memoryThread->id, $memoryResults[0]['id']);
        $this->assertSame(['User loves skiing trips', 'Enjoys gardening'], $memoryResults[0]['memories']);

        $messageResults = $this->manager->fetchContextSummaries($state, ['gardening']);
        $this->assertNotEmpty($messageResults);
        $this->assertContains($messageThread->id, array_column($messageResults, 'id'));
    }

    public function test_fetch_context_memories_prioritize_importance_and_limit(): void
    {
        /** @var AiThread $activeThread */
        $activeThread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
            'user_id' => 200,
            'last_message_at' => Carbon::now(),
        ]);

        AiMessage::factory()->create([
            'thread_id' => $activeThread->id,
            'assistant_key' => $activeThread->assistant_key,
            'user_id' => $activeThread->user_id,
            'role' => AiMessageRole::USER->value,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        /** @var AiThread $memoryThread */
        $memoryThread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
            'user_id' => $activeThread->user_id,
            'last_message_at' => Carbon::now()->subMinutes(1),
        ]);

        $now = Carbon::now();

        $highRecent = 'High priority recent focus';
        $highOlder = 'High priority older focus';
        $mediumRecent = 'Medium priority recent focus';

        foreach ([
            ['content' => $highRecent, 'importance' => 5, 'timestamp' => $now],
            ['content' => $highOlder, 'importance' => 5, 'timestamp' => $now->copy()->subMinutes(3)],
            ['content' => $mediumRecent, 'importance' => 3, 'timestamp' => $now->copy()->subMinutes(1)],
        ] as $memory) {
            AiMemory::factory()->create([
                'assistant_key' => $memoryThread->assistant_key,
                'thread_id' => $memoryThread->id,
                'user_id' => $memoryThread->user_id,
                'content' => $memory['content'],
                'importance' => $memory['importance'],
                'created_at' => $memory['timestamp'],
                'updated_at' => $memory['timestamp'],
            ]);
        }

        foreach (range(1, 27) as $index) {
            $timestamp = $now->copy()->subMinutes(10 + $index);

            AiMemory::factory()->create([
                'assistant_key' => $memoryThread->assistant_key,
                'thread_id' => $memoryThread->id,
                'user_id' => $memoryThread->user_id,
                'content' => "Low priority memory {$index}",
                'importance' => 1,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }

        AiMessage::factory()->create([
            'thread_id' => $memoryThread->id,
            'assistant_key' => $memoryThread->assistant_key,
            'user_id' => $memoryThread->user_id,
            'role' => AiMessageRole::USER->value,
            'status' => AiMessageStatus::COMPLETED->value,
            'content' => 'Discussed focus planning.',
        ]);

        $state = $this->stateService->forThread($activeThread);
        $results = $this->manager->fetchContextSummaries($state, ['focus']);

        $this->assertCount(1, $results);
        $memories = $results[0]['memories'];

        $this->assertCount(25, $memories);
        $this->assertSame($highRecent, $memories[0]);
        $this->assertSame($highOlder, $memories[1]);
        $this->assertSame($mediumRecent, $memories[2]);
        $this->assertStringContainsString('Low priority memory', $memories[24]);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../../database/migrations';
    }
}
