<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services;

use Atlas\Nexus\Enums\AiMessageContentType;
use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Jobs\PushThreadManagerAssistantJob;
use Atlas\Nexus\Jobs\RunAssistantResponseJob;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Threads\ThreadMessageService;
use Atlas\Nexus\Tests\Fixtures\Assistants\PrimaryAssistantDefinition;
use Atlas\Nexus\Tests\Fixtures\ThrowingTextRequestFactory;
use Atlas\Nexus\Tests\TestCase;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Queue;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

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

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
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

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
            'user_id' => 999,
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::ASSISTANT->value,
            'sequence' => 1,
            'status' => AiMessageStatus::PROCESSING->value,
        ]);

        $service = $this->app->make(ThreadMessageService::class);

        $this->expectException(\RuntimeException::class);

        $service->sendUserMessage($thread, 'Are you there?', $thread->user_id);

        Queue::assertPushed(PushThreadManagerAssistantJob::class);
    }

    public function test_it_dispatches_to_configured_queue_when_set(): void
    {
        Queue::fake();
        config()->set('atlas-nexus.queue', 'nexus-long-running');

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
            'user_id' => 555,
        ]);

        $service = $this->app->make(ThreadMessageService::class);
        $result = $service->sendUserMessage($thread, 'Hello queue', $thread->user_id);

        Queue::assertPushedOn('nexus-long-running', RunAssistantResponseJob::class, function (RunAssistantResponseJob $job) use ($result): bool {
            return $job->assistantMessageId === $result['assistant']->id;
        });
    }

    public function test_it_runs_inline_when_requested(): void
    {
        Queue::fake();

        PrimaryAssistantDefinition::updateConfig([
            'default_model' => 'gpt-inline',
        ]);

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
            'user_id' => 777,
        ]);

        /** @var \Illuminate\Support\Collection<int, \Prism\Prism\Contracts\Message> $messages */
        $messages = collect([
            new UserMessage('Hello inline'),
            new AssistantMessage('Inline reply'),
        ]);

        $response = new TextResponse(
            steps: collect([]),
            text: 'Inline reply',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(3, 4),
            meta: new Meta('inline-1', 'gpt-inline'),
            messages: $messages,
            additionalContent: [],
        );

        Prism::fake([$response]);

        $service = $this->app->make(ThreadMessageService::class);
        $result = $service->sendUserMessage($thread, 'Hello inline', $thread->user_id, AiMessageContentType::TEXT, false);

        Queue::assertPushed(PushThreadManagerAssistantJob::class);

        $assistantMessage = $result['assistant']->fresh();
        $this->assertInstanceOf(AiMessage::class, $assistantMessage);
        $this->assertTrue($assistantMessage->status === AiMessageStatus::COMPLETED);
        $this->assertSame('Inline reply', $assistantMessage->content);
        $this->assertSame('inline-1', $assistantMessage->provider_response_id);
        $this->assertIsArray($assistantMessage->raw_response);
        $this->assertSame('Inline reply', $assistantMessage->raw_response['text']);

        PrimaryAssistantDefinition::resetConfig();
    }

    public function test_it_marks_inline_failures_as_failed(): void
    {
        Queue::fake();

        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
            'user_id' => 888,
        ]);

        App::instance(\Atlas\Nexus\Integrations\Prism\TextRequestFactory::class, new ThrowingTextRequestFactory);

        $service = $this->app->make(ThreadMessageService::class);

        $this->expectException(\RuntimeException::class);

        try {
            $service->sendUserMessage($thread, 'Trigger failure', $thread->user_id, AiMessageContentType::TEXT, false);
        } finally {
            $assistantMessage = AiMessage::query()
                ->where('thread_id', $thread->id)
                ->where('role', AiMessageRole::ASSISTANT->value)
                ->orderByDesc('id')
                ->first();

            $this->assertInstanceOf(AiMessage::class, $assistantMessage);
            $this->assertTrue($assistantMessage->status === AiMessageStatus::FAILED);
            $this->assertSame('Simulated Prism failure', $assistantMessage->failed_reason);
        }
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
