<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Jobs;

use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Jobs\PushMemoryExtractorAssistantJob;
use Atlas\Nexus\Jobs\PushThreadManagerAssistantJob;
use Atlas\Nexus\Jobs\RunAssistantResponseJob;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Tests\TestCase;
use Illuminate\Support\Facades\Bus;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

class RunAssistantResponseJobTest extends TestCase
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

    public function test_it_updates_message_when_response_succeeds(): void
    {
        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        /** @var AiMessage $assistantMessage */
        $assistantMessage = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::ASSISTANT->value,
            'status' => AiMessageStatus::PROCESSING->value,
        ]);

        /** @var array<int, \Prism\Prism\Contracts\Message> $messageObjects */
        $messageObjects = [
            new UserMessage('Hello'),
            new AssistantMessage('All set!'),
        ];

        $response = new TextResponse(
            steps: collect([]),
            text: 'All set!',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(5, 7),
            meta: new Meta('resp-123', 'gpt-test'),
            messages: collect($messageObjects),
            additionalContent: [],
        );

        Prism::fake([$response]);

        RunAssistantResponseJob::dispatchSync($assistantMessage->id);

        $assistantMessage->refresh();

        $this->assertSame(AiMessageStatus::COMPLETED, $assistantMessage->status);
        $this->assertSame('All set!', $assistantMessage->content);
        $this->assertSame('resp-123', $assistantMessage->provider_response_id);
        $this->assertSame(5, $assistantMessage->tokens_in);
        $this->assertSame(7, $assistantMessage->tokens_out);
    }

    public function test_it_marks_message_failed_when_exception_thrown(): void
    {
        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        /** @var AiMessage $assistantMessage */
        $assistantMessage = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::ASSISTANT->value,
            'status' => AiMessageStatus::PROCESSING->value,
        ]);

        Prism::shouldReceive('text')
            ->andThrow(new \RuntimeException('Simulated failure'));

        try {
            RunAssistantResponseJob::dispatchSync($assistantMessage->id);
            $this->fail('Exception not thrown');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Simulated failure', $exception->getMessage());
        }

        $assistantMessage->refresh();
        $this->assertSame(AiMessageStatus::FAILED, $assistantMessage->status);
        $this->assertSame('Simulated failure', $assistantMessage->failed_reason);
    }

    public function test_it_dispatches_thread_manager_job_after_minimum_message_count_met(): void
    {
        Bus::fake([PushThreadManagerAssistantJob::class, PushMemoryExtractorAssistantJob::class]);
        config()->set('atlas-nexus.thread_summary.minimum_messages', 2);

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::USER->value,
            'sequence' => 1,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        /** @var AiMessage $assistantMessage */
        $assistantMessage = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::ASSISTANT->value,
            'sequence' => 2,
            'status' => AiMessageStatus::PROCESSING->value,
        ]);

        /** @var array<int, \Prism\Prism\Contracts\Message> $messageObjects */
        $messageObjects = [
            new UserMessage('Hello again'),
            new AssistantMessage('All set twice!'),
        ];

        $response = new TextResponse(
            steps: collect([]),
            text: 'Second reply done.',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(5, 5),
            meta: new Meta('resp-456', 'gpt-test'),
            messages: collect($messageObjects),
            additionalContent: [],
        );

        Prism::fake([$response]);

        RunAssistantResponseJob::dispatchSync($assistantMessage->id);

        $assistantMessage->refresh();
        $this->assertSame(AiMessageStatus::COMPLETED, $assistantMessage->status);

        Bus::assertDispatched(PushThreadManagerAssistantJob::class, function (PushThreadManagerAssistantJob $job) use ($thread): bool {
            return $job->threadId === $thread->id;
        });
    }

    public function test_it_dispatches_thread_manager_job_when_interval_reached_after_last_summary(): void
    {
        Bus::fake([PushThreadManagerAssistantJob::class, PushMemoryExtractorAssistantJob::class]);

        config()->set('atlas-nexus.thread_summary.message_interval', 3);
        config()->set('atlas-nexus.thread_summary.minimum_messages', 2);

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::USER->value,
            'sequence' => 1,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        /** @var AiMessage $firstAssistant */
        $firstAssistant = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::ASSISTANT->value,
            'sequence' => 2,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        $thread->update(['last_summary_message_id' => $firstAssistant->id]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::USER->value,
            'sequence' => 3,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::ASSISTANT->value,
            'sequence' => 4,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        /** @var AiMessage $assistantMessage */
        $assistantMessage = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::ASSISTANT->value,
            'sequence' => 5,
            'status' => AiMessageStatus::PROCESSING->value,
        ]);

        /** @var array<int, \Prism\Prism\Contracts\Message> $messageObjects */
        $messageObjects = [
            new UserMessage('Interval triggered'),
            new AssistantMessage('Interval met.'),
        ];

        $response = new TextResponse(
            steps: collect([]),
            text: 'Interval summary update.',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(6, 6),
            meta: new Meta('resp-789', 'gpt-test'),
            messages: collect($messageObjects),
            additionalContent: [],
        );

        Prism::fake([$response]);

        RunAssistantResponseJob::dispatchSync($assistantMessage->id);

        $assistantMessage->refresh();
        $this->assertSame(AiMessageStatus::COMPLETED, $assistantMessage->status);

        Bus::assertDispatched(PushThreadManagerAssistantJob::class, function (PushThreadManagerAssistantJob $job) use ($thread): bool {
            return $job->threadId === $thread->id;
        });
    }

    public function test_it_dispatches_memory_extractor_job_after_four_unchecked_messages(): void
    {
        Bus::fake([PushThreadManagerAssistantJob::class, PushMemoryExtractorAssistantJob::class]);
        config()->set('atlas-nexus.thread_summary.minimum_messages', 10);
        config()->set('atlas-nexus.memory_extractor.pending_message_count', 4);

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::USER->value,
            'sequence' => 1,
            'status' => AiMessageStatus::COMPLETED->value,
            'is_memory_checked' => false,
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::ASSISTANT->value,
            'sequence' => 2,
            'status' => AiMessageStatus::COMPLETED->value,
            'is_memory_checked' => false,
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::USER->value,
            'sequence' => 3,
            'status' => AiMessageStatus::COMPLETED->value,
            'is_memory_checked' => false,
        ]);

        /** @var AiMessage $assistantMessage */
        $assistantMessage = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::ASSISTANT->value,
            'sequence' => 4,
            'status' => AiMessageStatus::PROCESSING->value,
        ]);

        $response = new TextResponse(
            steps: collect([]),
            text: 'Reply for memory extraction test.',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(3, 3),
            meta: new Meta('resp-memory', 'gpt-test'),
            messages: collect([
                new UserMessage('Hi'),
                new AssistantMessage('Reply'),
            ]),
            additionalContent: [],
        );

        Prism::fake([$response]);

        RunAssistantResponseJob::dispatchSync($assistantMessage->id);

        Bus::assertDispatched(PushMemoryExtractorAssistantJob::class, function (PushMemoryExtractorAssistantJob $job) use ($thread): bool {
            return $job->threadId === $thread->id;
        });
    }

    public function test_it_uses_configured_memory_extractor_threshold(): void
    {
        Bus::fake([PushThreadManagerAssistantJob::class, PushMemoryExtractorAssistantJob::class]);
        config()->set('atlas-nexus.thread_summary.minimum_messages', 10);
        config()->set('atlas-nexus.memory_extractor.pending_message_count', 2);

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::USER->value,
            'sequence' => 1,
            'status' => AiMessageStatus::COMPLETED->value,
            'is_memory_checked' => false,
        ]);

        /** @var AiMessage $assistantMessage */
        $assistantMessage = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::ASSISTANT->value,
            'sequence' => 2,
            'status' => AiMessageStatus::PROCESSING->value,
        ]);

        $response = new TextResponse(
            steps: collect([]),
            text: 'Another reply.',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(3, 4),
            meta: new Meta('resp-memory-config', 'gpt-test'),
            messages: collect([
                new UserMessage('Message 1'),
                new AssistantMessage('Message 2'),
            ]),
            additionalContent: [],
        );

        Prism::fake([$response]);

        RunAssistantResponseJob::dispatchSync($assistantMessage->id);

        Bus::assertDispatched(PushMemoryExtractorAssistantJob::class, function (PushMemoryExtractorAssistantJob $job) use ($thread): bool {
            return $job->threadId === $thread->id;
        });
    }

    public function test_it_does_not_dispatch_when_interval_not_met(): void
    {
        Bus::fake([PushThreadManagerAssistantJob::class, PushMemoryExtractorAssistantJob::class]);

        config()->set('atlas-nexus.thread_summary.message_interval', 4);
        config()->set('atlas-nexus.thread_summary.minimum_messages', 2);

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::USER->value,
            'sequence' => 1,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        /** @var AiMessage $firstAssistant */
        $firstAssistant = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::ASSISTANT->value,
            'sequence' => 2,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        $thread->update(['last_summary_message_id' => $firstAssistant->id]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::USER->value,
            'sequence' => 3,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        /** @var AiMessage $assistantMessage */
        $assistantMessage = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::ASSISTANT->value,
            'sequence' => 4,
            'status' => AiMessageStatus::PROCESSING->value,
        ]);

        /** @var array<int, \Prism\Prism\Contracts\Message> $messageObjects */
        $messageObjects = [
            new UserMessage('Interval not met'),
            new AssistantMessage('Waiting.'),
        ];

        $response = new TextResponse(
            steps: collect([]),
            text: 'Not enough messages.',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(4, 4),
            meta: new Meta('resp-987', 'gpt-test'),
            messages: collect($messageObjects),
            additionalContent: [],
        );

        Prism::fake([$response]);

        RunAssistantResponseJob::dispatchSync($assistantMessage->id);

        $assistantMessage->refresh();
        $this->assertSame(AiMessageStatus::COMPLETED, $assistantMessage->status);

        Bus::assertNotDispatched(PushThreadManagerAssistantJob::class);
    }

    public function test_it_skips_dispatch_for_thread_manager_threads(): void
    {
        Bus::fake([PushThreadManagerAssistantJob::class, PushMemoryExtractorAssistantJob::class]);

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'thread-manager',
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::USER->value,
            'sequence' => 1,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        /** @var AiMessage $assistantMessage */
        $assistantMessage = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::ASSISTANT->value,
            'sequence' => 2,
            'status' => AiMessageStatus::PROCESSING->value,
        ]);

        /** @var \Illuminate\Support\Collection<int, \Prism\Prism\Contracts\Message> $messageObjects */
        $messageObjects = collect([
            new UserMessage('Review summary'),
            new AssistantMessage('Summary complete'),
        ]);

        $response = new TextResponse(
            steps: collect([]),
            text: 'Thread manager reply.',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(3, 3),
            meta: new Meta('resp-tm', 'gpt-thread-manager'),
            messages: $messageObjects,
            additionalContent: [],
        );

        Prism::fake([$response]);

        RunAssistantResponseJob::dispatchSync($assistantMessage->id);

        $assistantMessage->refresh();
        $this->assertSame(AiMessageStatus::COMPLETED, $assistantMessage->status);

        Bus::assertNotDispatched(PushThreadManagerAssistantJob::class);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
