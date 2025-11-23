<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Integrations\Prism\Tools;

use Atlas\Nexus\Enums\AiMessageContentType;
use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Enums\AiThreadStatus;
use Atlas\Nexus\Enums\AiThreadType;
use Atlas\Nexus\Integrations\Prism\Tools\ThreadManagerTool;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiAssistantPrompt;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Support\Chat\ThreadState;
use Atlas\Nexus\Tests\TestCase;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

/**
 * Class ThreadManagerToolTest
 *
 * Ensures the Prism tool can list, inspect, and summarize user threads.
 */
class ThreadManagerToolTest extends TestCase
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

    public function test_it_lists_threads_for_user(): void
    {
        $state = $this->createState();
        AiThread::factory()->create([
            'assistant_id' => $state->assistant->id,
            'user_id' => $state->thread->user_id,
            'status' => AiThreadStatus::OPEN->value,
            'title' => 'Follow Up Thread',
            'summary' => 'Short recap.',
            'long_summary' => 'Detailed recap for the follow up.',
            'metadata' => ['summary_keywords' => ['follow-up', 'recap']],
            'last_message_at' => now(),
        ]);
        $titleThread = AiThread::factory()->create([
            'assistant_id' => $state->assistant->id,
            'user_id' => $state->thread->user_id,
            'status' => AiThreadStatus::OPEN->value,
            'title' => 'Quarterly Budget Review',
            'summary' => 'Financial planning summary.',
            'long_summary' => 'Extended financial rundown.',
            'last_message_at' => now()->subHours(2),
        ]);
        $summaryThread = AiThread::factory()->create([
            'assistant_id' => $state->assistant->id,
            'user_id' => $state->thread->user_id,
            'status' => AiThreadStatus::OPEN->value,
            'title' => 'Inventory',
            'summary' => 'Contains roadmap insights for roadmap planning.',
            'long_summary' => 'Extended financial rundown.',
            'last_message_at' => now()->subHours(3),
        ]);
        $longSummaryThread = AiThread::factory()->create([
            'assistant_id' => $state->assistant->id,
            'user_id' => $state->thread->user_id,
            'status' => AiThreadStatus::OPEN->value,
            'title' => 'Support Tickets',
            'summary' => 'Support data.',
            'long_summary' => 'The long summary includes deep dive into retention metrics.',
            'last_message_at' => now()->subHours(4),
        ]);
        $keywordThread = AiThread::factory()->create([
            'assistant_id' => $state->assistant->id,
            'user_id' => $state->thread->user_id,
            'status' => AiThreadStatus::OPEN->value,
            'title' => 'Campaign Work',
            'summary' => 'Marketing review.',
            'long_summary' => 'Marketing review details.',
            'metadata' => ['summary_keywords' => ['ops', 'campaign']],
            'last_message_at' => now()->subHours(5),
        ]);
        $messageThread = AiThread::factory()->create([
            'assistant_id' => $state->assistant->id,
            'user_id' => $state->thread->user_id,
            'status' => AiThreadStatus::OPEN->value,
            'title' => 'Actions',
            'summary' => 'Pending action items.',
            'long_summary' => 'Pending action items and blockers listed in this thread.',
            'last_message_at' => now()->subDay(),
        ]);
        AiMessage::factory()->create([
            'thread_id' => $messageThread->id,
            'role' => AiMessageRole::USER->value,
            'status' => AiMessageStatus::COMPLETED->value,
            'sequence' => 1,
            'content' => 'Ledgernote details and transcripts for audit.',
            'content_type' => AiMessageContentType::TEXT->value,
        ]);
        AiThread::factory()->create([
            'assistant_id' => $state->assistant->id,
            'user_id' => $state->thread->user_id + 10,
            'status' => AiThreadStatus::OPEN->value,
        ]);

        $tool = $this->app->make(ThreadManagerTool::class);
        $tool->setThreadState($state);

        $response = $tool->handle([
            'action' => 'search_threads',
            'page' => 1,
        ]);

        $this->assertSame('Fetched matching threads.', $response->message());
        $threads = $response->meta()['result']['threads'];

        $this->assertCount(6, $threads);
        $firstThread = $threads[0];
        $this->assertArrayHasKey('id', $firstThread);
        $this->assertArrayHasKey('title', $firstThread);
        $this->assertArrayHasKey('summary', $firstThread);
        $hasKeywordMatch = false;

        foreach ($threads as $threadSummary) {
            if (($threadSummary['keywords'] ?? []) === ['follow-up', 'recap']) {
                $hasKeywordMatch = true;
                break;
            }
        }

        $this->assertTrue($hasKeywordMatch, 'Expected to find keywords from the follow-up thread.');

        $response = $tool->handle(['action' => 'search_threads', 'search' => ['follow-up']]);
        $filtered = $response->meta()['result']['threads'];
        $this->assertCount(1, $filtered);
        $this->assertSame('Follow Up Thread', $filtered[0]['title']);
        $this->assertArrayHasKey('id', $filtered[0]);
        $this->assertArrayHasKey('summary', $filtered[0]);

        $response = $tool->handle(['action' => 'search_threads', 'search' => ['quarterly budget']]);
        $filtered = $response->meta()['result']['threads'];
        $this->assertCount(1, $filtered);
        $this->assertSame('Quarterly Budget Review', $filtered[0]['title']);

        $response = $tool->handle(['action' => 'search_threads', 'search' => ['roadmap']]);
        $filtered = $response->meta()['result']['threads'];
        $this->assertCount(1, $filtered);
        $this->assertSame('Inventory', $filtered[0]['title']);

        $response = $tool->handle(['action' => 'search_threads', 'search' => ['retention metrics']]);
        $filtered = $response->meta()['result']['threads'];
        $this->assertCount(1, $filtered);
        $this->assertSame('Support Tickets', $filtered[0]['title']);

        $response = $tool->handle(['action' => 'search_threads', 'search' => ['campaign']]);
        $filtered = $response->meta()['result']['threads'];
        $this->assertCount(1, $filtered);
        $this->assertSame('Campaign Work', $filtered[0]['title']);

        $response = $tool->handle(['action' => 'search_threads', 'search' => ['ledgernote']]);
        $filtered = $response->meta()['result']['threads'];
        $this->assertCount(1, $filtered);
        $this->assertSame('Actions', $filtered[0]['title']);
    }

    public function test_it_filters_threads_between_dates(): void
    {
        $state = $this->createState();

        AiThread::factory()->create([
            'assistant_id' => $state->assistant->id,
            'user_id' => $state->thread->user_id,
            'status' => AiThreadStatus::OPEN->value,
            'title' => 'Old Thread',
            'summary' => 'Outside range.',
            'created_at' => now()->subDays(10),
            'updated_at' => now()->subDays(10),
            'last_message_at' => now()->subDays(10),
        ]);

        $recent = AiThread::factory()->create([
            'assistant_id' => $state->assistant->id,
            'user_id' => $state->thread->user_id,
            'status' => AiThreadStatus::OPEN->value,
            'title' => 'Recent Thread',
            'summary' => 'Inside range.',
            'created_at' => now()->subDays(2),
            'updated_at' => now()->subDays(2),
            'last_message_at' => now()->subDays(2),
        ]);

        $newest = AiThread::factory()->create([
            'assistant_id' => $state->assistant->id,
            'user_id' => $state->thread->user_id,
            'status' => AiThreadStatus::OPEN->value,
            'title' => 'Newest Thread',
            'summary' => 'Inside range too.',
            'created_at' => now()->subDay(),
            'updated_at' => now()->subDay(),
            'last_message_at' => now()->subDay(),
        ]);

        $tool = $this->app->make(ThreadManagerTool::class);
        $tool->setThreadState($state);

        $response = $tool->handle([
            'action' => 'search_threads',
            'between_dates' => [
                now()->subDays(3)->toDateString(),
                now()->toDateString(),
            ],
        ]);

        $threads = $response->meta()['result']['threads'];
        $titles = array_column($threads, 'title');

        $this->assertContains('Recent Thread', $titles);
        $this->assertContains('Newest Thread', $titles);
        $this->assertNotContains('Old Thread', $titles);
        $this->assertNotContains($state->thread->title, $titles);
    }

    public function test_search_threads_supports_multiple_terms_with_or_logic(): void
    {
        $state = $this->createState();

        $roadmapThread = AiThread::factory()->create([
            'assistant_id' => $state->assistant->id,
            'user_id' => $state->thread->user_id,
            'status' => AiThreadStatus::OPEN->value,
            'title' => 'Roadmap Planning',
            'summary' => 'Contains roadmap milestones.',
            'long_summary' => 'Detailed roadmap document.',
        ]);

        $ledgerThread = AiThread::factory()->create([
            'assistant_id' => $state->assistant->id,
            'user_id' => $state->thread->user_id,
            'status' => AiThreadStatus::OPEN->value,
            'title' => 'Finance Ledger',
            'summary' => 'Pending finance audits.',
            'long_summary' => 'Need to review ledgernote history.',
        ]);

        AiMessage::factory()->create([
            'thread_id' => $ledgerThread->id,
            'role' => AiMessageRole::USER->value,
            'status' => AiMessageStatus::COMPLETED->value,
            'sequence' => 1,
            'content' => 'Ledgernote compliance requires attention.',
            'content_type' => AiMessageContentType::TEXT->value,
        ]);

        $tool = $this->app->make(ThreadManagerTool::class);
        $tool->setThreadState($state);

        $response = $tool->handle([
            'action' => 'search_threads',
            'search' => ['roadmap', 'ledgernote'],
        ]);

        $titles = array_column($response->meta()['result']['threads'], 'title');

        $this->assertContains('Roadmap Planning', $titles);
        $this->assertContains('Finance Ledger', $titles);
    }

    public function test_it_fetches_thread_with_messages(): void
    {
        $state = $this->createState();

        /** @var AiThread $other */
        $other = AiThread::factory()->create([
            'assistant_id' => $state->assistant->id,
            'user_id' => $state->thread->user_id,
            'status' => AiThreadStatus::OPEN->value,
            'title' => 'Archived Thread',
            'summary' => 'Short summary',
            'long_summary' => 'Detailed context for archived thread.',
            'metadata' => ['summary_keywords' => ['archived']],
        ]);

        AiMessage::factory()->create([
            'thread_id' => $other->id,
            'role' => AiMessageRole::USER->value,
            'status' => AiMessageStatus::COMPLETED->value,
            'sequence' => 1,
            'content' => 'Need context.',
            'content_type' => AiMessageContentType::TEXT->value,
        ]);
        AiMessage::factory()->create([
            'thread_id' => $other->id,
            'role' => AiMessageRole::ASSISTANT->value,
            'status' => AiMessageStatus::COMPLETED->value,
            'sequence' => 2,
            'content' => 'Here is the requested context.',
            'content_type' => AiMessageContentType::TEXT->value,
        ]);

        $tool = $this->app->make(ThreadManagerTool::class);
        $tool->setThreadState($state);

        $response = $tool->handle([
            'action' => 'fetch_thread',
            'thread_id' => $other->id,
        ]);

        $this->assertSame('Fetched thread context.', $response->message());
        $payload = $response->meta()['result'];

        $this->assertSame('Archived Thread', $payload['title']);
        $this->assertSame(['archived'], $payload['keywords']);
        $this->assertCount(2, $payload['messages']);
        $this->assertSame('Need context.', $payload['messages'][0]['content']);
    }

    public function test_it_updates_thread_directly(): void
    {
        $state = $this->createState();

        $tool = $this->app->make(ThreadManagerTool::class);
        $tool->setThreadState($state);

        $response = $tool->handle([
            'action' => 'update_thread',
            'thread_id' => $state->thread->id,
            'title' => 'New Thread Title',
            'summary' => str_repeat('S', 400),
            'long_summary' => 'Extended overview of the discussion.',
        ]);

        $state->thread->refresh();

        $this->assertSame('Thread updated.', $response->message());
        $this->assertSame('New Thread Title', $state->thread->title);
        $this->assertSame(255, strlen((string) $state->thread->summary));
        $this->assertSame('Extended overview of the discussion.', $state->thread->long_summary);
    }

    public function test_it_generates_title_and_summary_inline(): void
    {
        config()->set('atlas-nexus.tools.options.thread_manager.model', 'gpt-4o-mini');

        $state = $this->createState(withMessages: true);

        /** @var \Illuminate\Support\Collection<int, \Prism\Prism\Contracts\Message> $messages */
        $messages = collect([
            new UserMessage('Generate title/summary'),
            new AssistantMessage('Summary generated.'),
        ]);

        Prism::fake([
            new TextResponse(
                steps: collect([]),
                text: json_encode([
                    'title' => 'Weekly Task Review',
                    'short_summary' => 'User reviewed outstanding tasks for the week.',
                    'long_summary' => 'User asked for a rundown of weekly tasks and deadlines. Assistant listed pending work and blockers requiring follow up.',
                    'keywords' => ['tasks', 'deadlines'],
                ], JSON_THROW_ON_ERROR),
                finishReason: FinishReason::Stop,
                toolCalls: [],
                toolResults: [],
                usage: new Usage(8, 16),
                meta: new Meta('thread-summary-1', 'gpt-4o-mini'),
                messages: $messages,
                additionalContent: [],
            ),
        ]);

        $this->app->make(\Atlas\Nexus\Services\Seeders\ThreadManagerAssistantSeeder::class)->seed();

        $tool = $this->app->make(ThreadManagerTool::class);
        $tool->setThreadState($state);

        $response = $tool->handle([
            'action' => 'update_thread',
            'generate_summary' => true,
        ]);

        $state->thread->refresh();

        $this->assertSame('Thread title and summaries generated.', $response->message());
        $this->assertSame('Weekly Task Review', $state->thread->title);
        $this->assertSame('User reviewed outstanding tasks for the week.', $state->thread->summary);
        $this->assertStringContainsString('rundown of weekly tasks', (string) $state->thread->long_summary);
        $this->assertSame(['tasks', 'deadlines'], $state->thread->metadata['summary_keywords'] ?? []);
    }

    public function test_it_errors_when_no_inputs_and_not_generating(): void
    {
        $state = $this->createState();

        $tool = $this->app->make(ThreadManagerTool::class);
        $tool->setThreadState($state);

        $response = $tool->handle([
            'action' => 'update_thread',
        ]);

        $this->assertSame('Provide a title, short summary, or long summary to update the thread.', $response->message());
        $this->assertArrayHasKey('error', $response->meta());
    }

    protected function createState(bool $withMessages = false): ThreadState
    {
        /** @var AiAssistant $assistant */
        $assistant = AiAssistant::query()->where('slug', 'thread-manager')->first()
            ?? AiAssistant::factory()->create([
                'slug' => 'thread-manager',
            ]);

        /** @var AiAssistantPrompt|null $prompt */
        $prompt = $assistant->current_prompt_id
            ? AiAssistantPrompt::query()->find($assistant->current_prompt_id)
            : null;

        if ($prompt === null) {
            $prompt = AiAssistantPrompt::factory()->create([
                'assistant_id' => $assistant->id,
            ]);
            $assistant->current_prompt_id = $prompt->id;
            $assistant->save();
        }

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'user_id' => 1,
            'type' => AiThreadType::USER->value,
            'status' => AiThreadStatus::OPEN->value,
            'title' => 'Original Title',
            'summary' => 'Original summary.',
            'long_summary' => 'Original long summary.',
        ]);

        $messages = collect();

        if ($withMessages) {
            /** @var AiMessage $user */
            $user = AiMessage::factory()->create([
                'thread_id' => $thread->id,
                'role' => AiMessageRole::USER->value,
                'sequence' => 1,
                'status' => AiMessageStatus::COMPLETED->value,
                'content' => 'What tasks remain this week?',
                'content_type' => AiMessageContentType::TEXT->value,
            ]);

            /** @var AiMessage $assistantMessage */
            $assistantMessage = AiMessage::factory()->create([
                'thread_id' => $thread->id,
                'role' => AiMessageRole::ASSISTANT->value,
                'sequence' => 2,
                'status' => AiMessageStatus::COMPLETED->value,
                'content' => 'You still need to complete the report and submit the budget draft.',
                'content_type' => AiMessageContentType::TEXT->value,
            ]);

            $messages = collect([$user, $assistantMessage]);
        }

        return new ThreadState(
            $thread,
            $assistant,
            $prompt,
            $messages,
            collect(),
            collect(),
            null,
            null,
            collect()
        );
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../../../database/migrations';
    }
}
