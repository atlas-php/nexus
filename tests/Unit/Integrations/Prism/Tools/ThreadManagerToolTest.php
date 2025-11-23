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
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiPrompt;
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
 * Verifies thread title/summary can be updated directly or generated inline.
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

    public function test_it_updates_title_and_summary_directly(): void
    {
        $state = $this->createState();

        $tool = $this->app->make(ThreadManagerTool::class);
        $tool->setThreadState($state);

        $response = $tool->handle([
            'title' => 'New Thread Title',
            'summary' => 'Updated summary content.',
        ]);

        $state->thread->refresh();

        $this->assertSame('Thread updated.', $response->message());
        $this->assertSame('New Thread Title', $state->thread->title);
        $this->assertSame('Updated summary content.', $state->thread->summary);
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
                    'summary' => 'User asked for a rundown of weekly tasks and follow-ups. Assistant listed pending items and deadlines.',
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
            'create_title_and_summary' => true,
        ]);

        $state->thread->refresh();

        $this->assertSame('Thread title and summary generated.', $response->message());
        $this->assertSame('Weekly Task Review', $state->thread->title);
        $this->assertStringContainsString('weekly tasks', (string) $state->thread->summary);
    }

    public function test_it_errors_when_no_inputs_and_not_generating(): void
    {
        $state = $this->createState();

        $tool = $this->app->make(ThreadManagerTool::class);
        $tool->setThreadState($state);

        $response = $tool->handle([]);

        $this->assertSame('Provide a title or summary to update the thread.', $response->message());
        $this->assertArrayHasKey('error', $response->meta());
    }

    protected function createState(bool $withMessages = false): ThreadState
    {
        /** @var AiAssistant $assistant */
        $assistant = AiAssistant::query()->where('slug', 'thread-manager')->first()
            ?? AiAssistant::factory()->create([
                'slug' => 'thread-manager',
            ]);

        /** @var AiPrompt|null $prompt */
        $prompt = $assistant->current_prompt_id
            ? AiPrompt::query()->find($assistant->current_prompt_id)
            : null;

        if ($prompt === null) {
            $prompt = AiPrompt::factory()->create([
                'version' => 1,
            ]);
            $assistant->current_prompt_id = $prompt->id;
            $assistant->save();
        }

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'prompt_id' => $prompt->id,
            'user_id' => 1,
            'type' => AiThreadType::USER->value,
            'status' => AiThreadStatus::OPEN->value,
            'title' => 'Original Title',
            'summary' => 'Original summary.',
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
