<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services\Threads;

use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Threads\ThreadMemoryExtractionService;
use Atlas\Nexus\Tests\TestCase;
use Illuminate\Support\Collection;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
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
            'User lives in Portland and enjoys gardening.',
        ];

        $encodedPayload = json_encode($payload, JSON_PRETTY_PRINT);

        if ($encodedPayload === false) {
            $this->fail('Failed to encode memory payload.');
        }

        /** @var Collection<int, \Prism\Prism\Contracts\Message> $messageObjects */
        $messageObjects = collect([
            new UserMessage('payload'),
            new AssistantMessage('done'),
        ]);

        $response = new TextResponse(
            steps: new Collection,
            text: $encodedPayload,
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(10, 15),
            meta: new Meta('memories-1', 'gpt-memory'),
            messages: $messageObjects,
            additionalContent: [],
        );

        Prism::fake([$response]);

        $service = $this->app->make(ThreadMemoryExtractionService::class);
        $service->extractFromMessages($thread, collect([$first, $second]));

        $firstFresh = $first->fresh();
        $secondFresh = $second->fresh();
        $this->assertInstanceOf(AiMessage::class, $firstFresh);
        $this->assertInstanceOf(AiMessage::class, $secondFresh);
        $this->assertTrue($firstFresh->is_memory_checked);
        $this->assertTrue($secondFresh->is_memory_checked);

        $memories = AiMemory::query()->where('thread_id', $thread->id)->get();
        $this->assertCount(1, $memories);
        $firstMemory = $memories->first();
        $this->assertInstanceOf(AiMemory::class, $firstMemory);
        $this->assertSame('User lives in Portland and enjoys gardening.', $firstMemory->content);
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

        /** @var Collection<int, \Prism\Prism\Contracts\Message> $emptyMessages */
        $emptyMessages = collect();

        $response = new TextResponse(
            steps: new Collection,
            text: 'not-json',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(5, 5),
            meta: new Meta('memories-error', 'gpt-memory'),
            messages: $emptyMessages,
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

        $freshMessage = $message->fresh();
        $this->assertInstanceOf(AiMessage::class, $freshMessage);
        $this->assertFalse($freshMessage->is_memory_checked);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../../database/migrations';
    }
}
