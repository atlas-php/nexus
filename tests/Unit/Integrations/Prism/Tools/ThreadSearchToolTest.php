<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Integrations\Prism\Tools;

use Atlas\Nexus\Enums\AiMessageContentType;
use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Enums\AiThreadStatus;
use Atlas\Nexus\Enums\AiThreadType;
use Atlas\Nexus\Integrations\Prism\Tools\ThreadSearchTool;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiAssistantPrompt;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Support\Chat\ThreadState;
use Atlas\Nexus\Tests\Fixtures\TestUser;
use Atlas\Nexus\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Class ThreadSearchToolTest
 *
 * Ensures the Prism tool can list threads for search flows.
 */
class ThreadSearchToolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('auth.providers.users.model', TestUser::class);
        config()->set('auth.model', TestUser::class);

        $this->loadPackageMigrations($this->migrationPath());
        $this->runPendingCommand('migrate:fresh', [
            '--path' => $this->migrationPath(),
            '--realpath' => true,
        ])->run();

        Schema::dropIfExists('users');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });
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

        $tool = $this->app->make(ThreadSearchTool::class);
        $tool->setThreadState($state);

        $response = $tool->handle([
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

        $response = $tool->handle(['search' => ['follow-up']]);
        $filtered = $response->meta()['result']['threads'];
        $this->assertCount(1, $filtered);
        $this->assertSame('Follow Up Thread', $filtered[0]['title']);
        $this->assertArrayHasKey('id', $filtered[0]);
        $this->assertArrayHasKey('summary', $filtered[0]);

        $response = $tool->handle(['search' => ['quarterly budget']]);
        $filtered = $response->meta()['result']['threads'];
        $this->assertCount(1, $filtered);
        $this->assertSame('Quarterly Budget Review', $filtered[0]['title']);

        $response = $tool->handle(['search' => ['roadmap']]);
        $filtered = $response->meta()['result']['threads'];
        $this->assertCount(1, $filtered);
        $this->assertSame('Inventory', $filtered[0]['title']);

        $response = $tool->handle(['search' => ['retention metrics']]);
        $filtered = $response->meta()['result']['threads'];
        $this->assertCount(1, $filtered);
        $this->assertSame('Support Tickets', $filtered[0]['title']);

        $response = $tool->handle(['search' => ['campaign']]);
        $filtered = $response->meta()['result']['threads'];
        $this->assertCount(1, $filtered);
        $this->assertSame('Campaign Work', $filtered[0]['title']);

        $response = $tool->handle(['search' => ['ledgernote']]);
        $filtered = $response->meta()['result']['threads'];
        $this->assertCount(1, $filtered);
        $this->assertSame('Actions', $filtered[0]['title']);
    }

    public function test_it_guides_when_search_results_are_empty(): void
    {
        $state = $this->createState();

        AiThread::factory()->create([
            'assistant_id' => $state->assistant->id,
            'user_id' => $state->thread->user_id,
            'status' => AiThreadStatus::OPEN->value,
            'title' => 'Quarterly Planning',
            'summary' => 'Roadmap for Q3.',
            'last_message_at' => now(),
        ]);

        $tool = $this->app->make(ThreadSearchTool::class);
        $tool->setThreadState($state);

        $response = $tool->handle([
            'search' => ['Unknown Query'],
        ]);

        $this->assertSame(
            'No threads matched those keywords. Search checks thread titles, summaries, keywords, message content, and user names. Use Fetch Thread Content with the thread_ids parameter to inspect a specific conversation.',
            $response->message()
        );
        $this->assertSame([], $response->meta()['result']['threads']);
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

        $tool = $this->app->make(ThreadSearchTool::class);
        $tool->setThreadState($state);

        $response = $tool->handle([
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

        $tool = $this->app->make(ThreadSearchTool::class);
        $tool->setThreadState($state);

        $response = $tool->handle([
            'search' => ['roadmap', 'ledgernote'],
        ]);

        $titles = array_column($response->meta()['result']['threads'], 'title');

        $this->assertContains('Roadmap Planning', $titles);
        $this->assertContains('Finance Ledger', $titles);
    }

    public function test_search_by_user_name_returns_latest_threads(): void
    {
        $state = $this->createState();

        $latest = AiThread::factory()->create([
            'assistant_id' => $state->assistant->id,
            'user_id' => $state->thread->user_id,
            'status' => AiThreadStatus::OPEN->value,
            'title' => 'Latest Thread',
            'summary' => 'Latest summary.',
            'last_message_at' => now(),
        ]);

        $older = AiThread::factory()->create([
            'assistant_id' => $state->assistant->id,
            'user_id' => $state->thread->user_id,
            'status' => AiThreadStatus::OPEN->value,
            'title' => 'Older Thread',
            'summary' => 'Older summary.',
            'last_message_at' => now()->subHours(2),
        ]);

        $tool = $this->app->make(ThreadSearchTool::class);
        $tool->setThreadState($state);

        $userName = TestUser::query()->find($state->thread->user_id)?->name ?? '';

        $response = $tool->handle([
            'search' => [$userName],
        ]);

        $threads = $response->meta()['result']['threads'];

        $this->assertSame([$latest->id, $older->id], array_column($threads, 'id'));
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

        $user = TestUser::query()->create([
            'name' => 'Maggie Smith',
            'email' => 'maggie@example.com',
        ]);

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'user_id' => $user->id,
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
