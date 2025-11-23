<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Jobs;

use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Jobs\RunAssistantResponseJob;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Tests\TestCase;
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

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
