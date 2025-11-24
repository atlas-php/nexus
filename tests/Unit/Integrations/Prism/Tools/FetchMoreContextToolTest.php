<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Integrations\Prism\Tools;

use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Integrations\Prism\Tools\FetchMoreContextTool;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Threads\ThreadManagerService;
use Atlas\Nexus\Services\Threads\ThreadMemoryService;
use Atlas\Nexus\Services\Threads\ThreadStateService;
use Atlas\Nexus\Tests\TestCase;
use Illuminate\Support\Carbon;

class FetchMoreContextToolTest extends TestCase
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

    public function test_it_returns_latest_threads_when_no_search(): void
    {
        /** @var AiThread $activeThread */
        $activeThread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
            'user_id' => 1,
            'last_message_at' => Carbon::now(),
        ]);

        AiMessage::factory()->create([
            'thread_id' => $activeThread->id,
            'assistant_key' => $activeThread->assistant_key,
            'user_id' => $activeThread->user_id,
            'role' => AiMessageRole::USER->value,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        foreach (range(1, 11) as $minutes) {
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
            ]);
        }

        $state = $this->stateService->forThread($activeThread);
        $tool = new FetchMoreContextTool($this->manager);
        $tool->setThreadState($state);

        $response = $tool->handle([]);

        $this->assertCount(10, $response->meta()['result']['threads']);
        $this->assertStringContainsString('Thread Id:', $response->message());
    }

    public function test_it_filters_threads_by_memories(): void
    {
        /** @var AiThread $activeThread */
        $activeThread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
            'user_id' => 55,
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

        AiMemory::factory()->create([
            'assistant_id' => $memoryThread->assistant_key,
            'thread_id' => $memoryThread->id,
            'user_id' => $memoryThread->user_id,
            'content' => 'Prefers remote skiing trips',
        ]);

        AiMessage::factory()->create([
            'thread_id' => $memoryThread->id,
            'assistant_key' => $memoryThread->assistant_key,
            'user_id' => $memoryThread->user_id,
            'role' => AiMessageRole::USER->value,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        $memories = $this->app->make(ThreadMemoryService::class)->memoriesForThread($memoryThread);
        $this->assertCount(1, $memories);

        $state = $this->stateService->forThread($activeThread);
        $tool = new FetchMoreContextTool($this->manager);
        $tool->setThreadState($state);

        $response = $tool->handle(['search' => ['skiing']]);
        $threads = $response->meta()['result']['threads'] ?? [];

        $this->assertCount(1, $threads);
        $this->assertSame($memoryThread->id, $threads[0]['id']);
        $this->assertSame(['Prefers remote skiing trips'], $threads[0]['memories']);
        $this->assertStringContainsString('Memories:', $response->message());
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../../../database/migrations';
    }
}
