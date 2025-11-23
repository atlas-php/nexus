<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Integrations\Prism\Tools;

use Atlas\Nexus\Enums\AiMessageContentType;
use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Enums\AiThreadStatus;
use Atlas\Nexus\Enums\AiThreadType;
use Atlas\Nexus\Integrations\Prism\Tools\ThreadFetcherTool;
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
 * Class ThreadFetcherToolTest
 *
 * Ensures the Prism tool fetches single or multiple threads for inspection.
 */
class ThreadFetcherToolTest extends TestCase
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

    public function test_it_fetches_thread_with_messages(): void
    {
        $state = $this->createState(withMessages: true);

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

        $tool = $this->app->make(ThreadFetcherTool::class);
        $tool->setThreadState($state);

        $response = $tool->handle([
            'thread_ids' => [$other->id],
        ]);

        $this->assertSame('Fetched thread context.', $response->message());
        $payload = $response->meta()['result'];
        $this->assertSame([$other->id], $response->meta()['thread_ids']);

        $this->assertSame('Archived Thread', $payload['title']);
        $this->assertSame(['archived'], $payload['keywords']);
        $this->assertCount(2, $payload['messages']);
        $this->assertSame('Need context.', $payload['messages'][0]['content']);
    }

    public function test_it_fetches_multiple_threads_in_requested_order(): void
    {
        $state = $this->createState();

        $first = AiThread::factory()->create([
            'assistant_id' => $state->assistant->id,
            'user_id' => $state->thread->user_id,
            'status' => AiThreadStatus::OPEN->value,
            'title' => 'First Thread',
            'summary' => 'First summary.',
            'last_message_at' => now()->subDay(),
        ]);

        $second = AiThread::factory()->create([
            'assistant_id' => $state->assistant->id,
            'user_id' => $state->thread->user_id,
            'status' => AiThreadStatus::OPEN->value,
            'title' => 'Second Thread',
            'summary' => 'Second summary.',
            'last_message_at' => now(),
        ]);

        $tool = $this->app->make(ThreadFetcherTool::class);
        $tool->setThreadState($state);

        $response = $tool->handle([
            'thread_ids' => [$second->id, $first->id],
        ]);

        $this->assertSame('Fetched 2 threads.', $response->message());
        $meta = $response->meta();

        $this->assertSame([$second->id, $first->id], $meta['thread_ids']);
        $threads = $meta['result']['threads'];

        $this->assertCount(2, $threads);
        $this->assertSame($second->id, $threads[0]['id']);
        $this->assertSame($first->id, $threads[1]['id']);
    }

    public function test_it_accepts_string_thread_ids(): void
    {
        $state = $this->createState();

        $thread = AiThread::factory()->create([
            'assistant_id' => $state->assistant->id,
            'user_id' => $state->thread->user_id,
            'status' => AiThreadStatus::OPEN->value,
            'title' => 'String Thread',
        ]);

        $tool = $this->app->make(ThreadFetcherTool::class);
        $tool->setThreadState($state);

        $response = $tool->handle([
            'thread_ids' => [(string) $thread->id],
        ]);

        $this->assertSame('Fetched thread context.', $response->message());
        $this->assertSame([$thread->id], $response->meta()['thread_ids']);
    }

    public function test_it_requires_thread_identifier(): void
    {
        $state = $this->createState();

        $tool = $this->app->make(ThreadFetcherTool::class);
        $tool->setThreadState($state);

        $response = $tool->handle([
            'thread_ids' => [],
        ]);

        $this->assertSame('Provide at least one thread_id to fetch.', $response->message());
        $this->assertTrue($response->meta()['error'] ?? false);
    }

    public function test_it_errors_when_thread_is_missing(): void
    {
        $state = $this->createState();

        $tool = $this->app->make(ThreadFetcherTool::class);
        $tool->setThreadState($state);

        $response = $tool->handle([
            'thread_ids' => [$state->thread->id + 999],
        ]);

        $this->assertSame('Thread not found for this assistant and user.', $response->message());
        $this->assertTrue($response->meta()['error'] ?? false);
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
