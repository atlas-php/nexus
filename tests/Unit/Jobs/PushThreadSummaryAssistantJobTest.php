<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Jobs;

use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Integrations\Prism\TextRequest;
use Atlas\Nexus\Integrations\Prism\TextRequestFactory;
use Atlas\Nexus\Jobs\PushThreadSummaryAssistantJob;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Tests\TestCase;
use Mockery;
use Mockery\Expectation;
use Mockery\ExpectationInterface;
use Mockery\MockInterface;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Text\Response;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

/**
 * Class PushThreadSummaryAssistantJobTest
 *
 * Verifies the job generates summaries using the thread summary assistant.
 */
class PushThreadSummaryAssistantJobTest extends TestCase
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

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_it_generates_summary_via_thread_summary_assistant(): void
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
            'content' => 'Hello Nexus',
        ]);

        $this->mockTextRequestFactory('{"title":"Conversation","summary":"Quick summary.","keywords":["conversation"]}');

        PushThreadSummaryAssistantJob::dispatchSync($thread->id);

        $thread->refresh();
        $this->assertSame($message->id, $thread->last_summary_message_id);
        $this->assertSame('Quick summary.', $thread->summary);
        $metadata = $thread->metadata ?? [];
        $this->assertSame(['conversation'], $metadata['keywords'] ?? null);
    }

    public function test_it_skips_context_prompt_messages_when_summarizing(): void
    {
        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::ASSISTANT->value,
            'sequence' => 1,
            'status' => AiMessageStatus::COMPLETED->value,
            'is_context_prompt' => true,
        ]);

        /** @var AiMessage $userMessage */
        $userMessage = AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::USER->value,
            'sequence' => 2,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        $this->mockTextRequestFactory('{"title":"Conversation","summary":"Updated summary.","keywords":["conversation"]}');

        PushThreadSummaryAssistantJob::dispatchSync($thread->id);

        $thread->refresh();
        $this->assertSame($userMessage->id, $thread->last_summary_message_id);
        $this->assertSame('Updated summary.', $thread->summary);
    }

    protected function mockTextRequestFactory(string $responseText): void
    {
        /** @var TextRequestFactory&MockInterface $factory */
        $factory = Mockery::mock(TextRequestFactory::class);
        /** @var TextRequest&MockInterface $request */
        $request = Mockery::mock(TextRequest::class);

        $this->expect($factory, 'make')->once()->andReturn($request);
        $this->expect($request, 'using')->once()->andReturnSelf();
        $this->expect($request, 'withSystemPrompt')->once()->andReturnSelf();
        $this->expect($request, 'withMessages')->once()->andReturnSelf();
        $this->expect($request, 'withMaxSteps')->once()->andReturnSelf();
        $this->expect($request, 'withMaxTokens')->once()->andReturnSelf();
        $this->expect($request, 'withProviderOptions')->zeroOrMoreTimes()->andReturnSelf();
        $this->expect($request, 'asText')->andReturn($this->fakeResponse($responseText));

        $this->app->instance(TextRequestFactory::class, $factory);
    }

    protected function fakeResponse(string $text): Response
    {
        return new Response(
            collect(),
            $text,
            FinishReason::Stop,
            [],
            [],
            new Usage(10, 20),
            new Meta('resp-123', 'gpt-4o-mini'),
            collect()
        );
    }

    /**
     * @return ExpectationInterface
     *
     * @phpstan-return Expectation
     */
    protected function expect(MockInterface $mock, string $method)
    {
        /** @phpstan-ignore-next-line */
        return $mock->shouldReceive($method);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
