<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services;

use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Jobs\RunAssistantResponseJob;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiPrompt;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Threads\ThreadMessageService;
use Atlas\Nexus\Tests\TestCase;
use Illuminate\Support\Facades\Queue;

/**
 * Class ThreadMessageServiceTest
 *
 * Ensures user messages are recorded and assistant jobs are dispatched per thread.
 */
class ThreadMessageServiceTest extends TestCase
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

    public function test_it_dispatches_response_job_after_recording_messages(): void
    {
        Queue::fake();

        $assistant = AiAssistant::factory()->create(['slug' => 'thread-message']);
        $prompt = AiPrompt::factory()->create([
            'assistant_id' => $assistant->id,
            'version' => 1,
        ]);
        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'prompt_id' => $prompt->id,
            'user_id' => 321,
        ]);

        $service = $this->app->make(ThreadMessageService::class);

        $result = $service->sendUserMessage($thread, 'Hello Nexus', 654);

        $this->assertSame('Hello Nexus', $result['user']->content);
        $this->assertTrue($result['user']->status === AiMessageStatus::COMPLETED);
        $this->assertTrue($result['assistant']->status === AiMessageStatus::PROCESSING);
        $this->assertSame(1, $result['user']->sequence);
        $this->assertSame(2, $result['assistant']->sequence);
        $this->assertNotNull($thread->fresh()->last_message_at);

        Queue::assertPushed(RunAssistantResponseJob::class, function (RunAssistantResponseJob $job) use ($result): bool {
            return $job->assistantMessageId === $result['assistant']->id;
        });
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
