<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services;

use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Threads\ThreadStateService;
use Atlas\Nexus\Services\Threads\ThreadTitleSummaryService;
use Atlas\Nexus\Tests\TestCase;
use Illuminate\Support\Collection;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

use function collect;

/**
 * Class ThreadTitleSummaryServiceTest
 *
 * Ensures thread manager summaries are generated and logged via dedicated assistant threads.
 */
class ThreadTitleSummaryServiceTest extends TestCase
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

    public function test_it_generates_summary_and_logs_thread_manager_thread(): void
    {
        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
            'user_id' => 42,
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'user_id' => $thread->user_id,
            'role' => AiMessageRole::USER->value,
            'content' => 'Hello, summarize my work today.',
            'sequence' => 1,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::ASSISTANT->value,
            'content' => 'Sure, here are the details.',
            'sequence' => 2,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        $payload = [
            'title' => 'Work summary complete',
            'summary' => 'Completed all assigned work and provided updates.',
            'keywords' => ['work', 'updates'],
        ];

        /** @var \Illuminate\Support\Collection<int, \Prism\Prism\Contracts\Message> $messageObjects */
        $messageObjects = collect([
            new UserMessage('Summarize this thread'),
            new AssistantMessage('Summary generated.'),
        ]);

        $response = new TextResponse(
            steps: new Collection,
            text: "```json\n".json_encode($payload, JSON_PRETTY_PRINT)."\n```",
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(10, 12),
            meta: new Meta('resp-summary', 'gpt-summary'),
            messages: $messageObjects,
            additionalContent: [],
        );

        Prism::fake([$response]);

        $state = $this->app->make(ThreadStateService::class)->forThread($thread);
        $service = $this->app->make(ThreadTitleSummaryService::class);

        $result = $service->generateAndSave($state);

        $this->assertSame(['work', 'updates'], $result['keywords']);

        $summaryThread = AiThread::query()
            ->where('assistant_key', 'thread-manager')
            ->where('parent_thread_id', $thread->id)
            ->first();

        $this->assertNotNull($summaryThread);
        $this->assertNull($summaryThread->summary);
        $this->assertSame($thread->id, $summaryThread->parent_thread_id);

        /** @var array<int, AiMessage> $messages */
        $messages = AiMessage::query()
            ->where('thread_id', $summaryThread->id)
            ->orderBy('sequence')
            ->get()
            ->all();

        $this->assertCount(2, $messages);
        $this->assertSame(AiMessageRole::USER, $messages[0]->role);
        $this->assertSame(AiMessageRole::ASSISTANT, $messages[1]->role);
        $this->assertSame('resp-summary', $messages[1]->provider_response_id);
        $this->assertSame($response->text, $messages[1]->metadata['thread_manager_payload'] ?? null);
        $this->assertIsArray($messages[1]->raw_response);
        $this->assertSame($response->text, $messages[1]->raw_response['text'] ?? null);

        $metadata = $summaryThread->metadata ?? [];
        $this->assertSame($response->text, $metadata['thread_manager_payload'] ?? null);
    }

    public function test_it_uses_partial_context_when_previous_summary_exists(): void
    {
        $existingSummary = 'Completed week 1 planning.';

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
            'summary' => $existingSummary,
        ]);

        /** @var AiMessage $oldUser */
        $oldUser = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'user_id' => $thread->user_id,
            'role' => AiMessageRole::USER->value,
            'content' => 'Old context message.',
            'sequence' => 1,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        /** @var AiMessage $oldAssistant */
        $oldAssistant = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::ASSISTANT->value,
            'content' => 'Old assistant response.',
            'sequence' => 2,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        $thread->forceFill([
            'summary' => $existingSummary,
            'last_summary_message_id' => $oldAssistant->id,
        ])->save();

        /** @var AiMessage $newUser */
        $newUser = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'user_id' => $thread->user_id,
            'role' => AiMessageRole::USER->value,
            'content' => 'New updates after the last summary.',
            'sequence' => 3,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::ASSISTANT->value,
            'content' => 'Assistant acknowledged the new update.',
            'sequence' => 4,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        $payload = [
            'title' => 'Work summary complete',
            'summary' => 'Completed all assigned work and provided updates.',
            'keywords' => ['work', 'updates'],
        ];

        $response = new TextResponse(
            steps: new Collection,
            text: "```json\n".json_encode($payload, JSON_PRETTY_PRINT)."\n```",
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(10, 12),
            meta: new Meta('resp-summary', 'gpt-summary'),
            messages: collect([]),
            additionalContent: [],
        );

        $fake = Prism::fake([$response]);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);
        $service = $this->app->make(ThreadTitleSummaryService::class);

        $service->generateAndSave($state);

        $fake->assertRequest(function (array $requests) use ($existingSummary, $newUser, $oldUser): void {
            $this->assertNotEmpty($requests);

            /** @var \Prism\Prism\Text\Request $request */
            $request = $requests[0];
            $messages = $request->messages();

            $this->assertCount(1, $messages);
            $this->assertInstanceOf(\Prism\Prism\ValueObjects\Messages\UserMessage::class, $messages[0]);

            /** @var \Prism\Prism\ValueObjects\Messages\UserMessage $message */
            $message = $messages[0];
            $text = $message->text();

            $this->assertStringContainsString('Current thread summary:', $text);
            $this->assertStringContainsString($existingSummary, $text);
            $this->assertStringContainsString('Recent messages:', $text);
            $this->assertStringContainsString('USER: '.$newUser->content, $text);
            $this->assertStringNotContainsString('USER: '.$oldUser->content, $text);
        });
    }

    public function test_partial_context_labels_missing_summary_as_none(): void
    {
        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        /** @var AiMessage $oldAssistant */
        $oldAssistant = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::ASSISTANT->value,
            'content' => 'Previous summary response.',
            'sequence' => 2,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        $thread->forceFill([
            'summary' => null,
            'last_summary_message_id' => $oldAssistant->id,
        ])->save();

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'user_id' => $thread->user_id,
            'role' => AiMessageRole::USER->value,
            'content' => 'Latest update needing context.',
            'sequence' => 3,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        $payload = [
            'title' => 'Updated summary',
            'summary' => 'Something new happened.',
            'keywords' => ['update'],
        ];

        $response = new TextResponse(
            steps: new Collection,
            text: "```json\n".json_encode($payload, JSON_PRETTY_PRINT)."\n```",
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [],
            usage: new Usage(10, 12),
            meta: new Meta('resp-summary', 'gpt-summary'),
            messages: collect([]),
            additionalContent: [],
        );

        $fake = Prism::fake([$response]);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);
        $service = $this->app->make(ThreadTitleSummaryService::class);

        $service->generateAndSave($state);

        $fake->assertRequest(function (array $requests): void {
            $this->assertNotEmpty($requests);
            /** @var \Prism\Prism\Text\Request $request */
            $request = $requests[0];
            $messages = $request->messages();

            $this->assertCount(1, $messages);
            $this->assertInstanceOf(\Prism\Prism\ValueObjects\Messages\UserMessage::class, $messages[0]);
            $text = $messages[0]->text();

            $this->assertStringContainsString('Current thread summary:', $text);
            $this->assertStringContainsString('None', $text);
        });
    }


    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
