<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services\Threads;

use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Threads\ThreadMemoryExtractionService;
use Atlas\Nexus\Tests\TestCase;
use Illuminate\Support\Collection;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;

class ThreadMemoryExtractionServiceTest extends TestCase
{
    protected function shouldUseAssistantFixtures(): bool
    {
        return false;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadPackageMigrations($this->migrationPath());
        $this->runPendingCommand('migrate:fresh', [
            '--path' => $this->migrationPath(),
            '--realpath' => true,
        ])->run();
    }

    public function test_it_appends_memories_and_marks_messages_checked(): void
    {
        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
            'user_id' => 99,
        ]);

        /** @var AiMessage $first */
        $first = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::USER->value,
            'content' => 'I live in Portland and love gardening.',
            'sequence' => 1,
            'status' => AiMessageStatus::COMPLETED->value,
            'is_memory_checked' => false,
        ]);

        /** @var AiMessage $second */
        $second = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::ASSISTANT->value,
            'content' => 'Great! What plants do you grow?',
            'sequence' => 2,
            'status' => AiMessageStatus::COMPLETED->value,
            'is_memory_checked' => false,
        ]);

        $payload = [
            'memories' => [
                [
                    'content' => 'User lives in Portland and enjoys gardening.',
                    'source_message_ids' => [$first->id, $second->id],
                ],
            ],
        ];

        $response = new TextResponse(
            steps: new Collection,
            text: json_encode($payload, JSON_PRETTY_PRINT),
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(10, 15),
            meta: new Meta('memories-1', 'gpt-memory'),
            messages: new Collection([
                new UserMessage('payload'),
                new AssistantMessage('done'),
            ]),
            additionalContent: [],
        );

        Prism::fake([$response]);

        $service = $this->app->make(ThreadMemoryExtractionService::class);
        $service->extractFromMessages($thread, collect([$first, $second]));

        $this->assertTrue($first->fresh()->is_memory_checked);
        $this->assertTrue($second->fresh()->is_memory_checked);

        $updatedThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $updatedThread);
        $this->assertCount(1, $updatedThread->memories);
        $this->assertSame(
            [$first->id, $second->id],
            $updatedThread->memories[0]['source_message_ids']
        );
    }

    public function test_it_throws_when_response_payload_is_invalid(): void
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
            'is_memory_checked' => false,
        ]);

        $response = new TextResponse(
            steps: new Collection,
            text: 'not-json',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(5, 5),
            meta: new Meta('memories-error', 'gpt-memory'),
            messages: new Collection,
            additionalContent: [],
        );

        Prism::fake([$response]);

        $service = $this->app->make(ThreadMemoryExtractionService::class);

        try {
            $service->extractFromMessages($thread, collect([$message]));
            $this->fail('Expected RuntimeException to be thrown.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Unable to decode memory extraction response', $exception->getMessage());
        }

        $this->assertFalse($message->fresh()->is_memory_checked);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../../database/migrations';
    }
}
