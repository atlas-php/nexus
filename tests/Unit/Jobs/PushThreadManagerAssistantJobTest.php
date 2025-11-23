<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Jobs;

use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Jobs\PushThreadManagerAssistantJob;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Threads\ThreadTitleSummaryService;
use Atlas\Nexus\Tests\TestCase;
use Mockery;
use Mockery\MockInterface;

/**
 * Class PushThreadManagerAssistantJobTest
 *
 * Verifies the job delegates summary generation to the thread manager assistant.
 */
class PushThreadManagerAssistantJobTest extends TestCase
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

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_generates_summary_via_thread_manager_assistant(): void
    {
        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        /** @var AiMessage $message */
        $message = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::USER->value,
            'sequence' => 1,
            'status' => AiMessageStatus::COMPLETED->value,
            'content' => 'Hello Nexus',
        ]);

        /** @var ThreadTitleSummaryService&MockInterface $summaryService */
        $summaryService = Mockery::mock(ThreadTitleSummaryService::class);
        /** @phpstan-ignore-next-line dynamic expectation helper */
        $summaryService->shouldReceive('generateAndSave')
            ->andReturn([
                'thread' => $thread,
                'title' => 'Conversation',
                'summary' => 'Quick summary.',
                'keywords' => ['conversation'],
            ]);

        $this->app->instance(ThreadTitleSummaryService::class, $summaryService);

        PushThreadManagerAssistantJob::dispatchSync($thread->id);

        $thread->refresh();
        $this->assertSame($message->id, $thread->last_summary_message_id);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
