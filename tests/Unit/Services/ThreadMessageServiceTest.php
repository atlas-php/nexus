<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services;

use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Jobs\RunAssistantResponseJob;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiMessage;
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

        /** @var AiAssistant $assistant */
        $assistant = AiAssistant::factory()->create(['slug' => 'thread-message']);
        /** @var AiPrompt $prompt */
        $prompt = AiPrompt::factory()->create([
            'assistant_id' => $assistant->id,
            'version' => 1,
        ]);
        /** @var AiThread $thread */
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
        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);
        $this->assertNotNull($freshThread->last_message_at);

        Queue::assertPushed(RunAssistantResponseJob::class, function (RunAssistantResponseJob $job) use ($result): bool {
            return $job->assistantMessageId === $result['assistant']->id;
        });
    }

    public function test_it_blocks_new_messages_when_assistant_still_processing(): void
    {
        Queue::fake();

        /** @var AiAssistant $assistant */
        $assistant = AiAssistant::factory()->create(['slug' => 'thread-blocking']);
        /** @var AiPrompt $prompt */
        $prompt = AiPrompt::factory()->create([
            'assistant_id' => $assistant->id,
            'version' => 1,
        ]);
        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'prompt_id' => $prompt->id,
            'user_id' => 999,
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'role' => AiMessageRole::ASSISTANT->value,
            'sequence' => 1,
            'status' => AiMessageStatus::PROCESSING->value,
        ]);

        $service = $this->app->make(ThreadMessageService::class);

        $this->expectException(\RuntimeException::class);

        $service->sendUserMessage($thread, 'Are you there?', $thread->user_id);

        Queue::assertNothingPushed();
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
