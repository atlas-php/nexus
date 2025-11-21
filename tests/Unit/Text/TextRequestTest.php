<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Text;

use Atlas\Nexus\Integrations\Prism\TextRequest;
use Atlas\Nexus\Support\Chat\ChatThreadLog;
use Atlas\Nexus\Tests\TestCase;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

use function collect;

/**
 * Class TextRequestTest
 *
 * Confirms Prism text requests remain configurable while logging chat threads through Nexus.
 */
class TextRequestTest extends TestCase
{
    public function test_it_logs_responses_and_invokes_user_completion_callback(): void
    {
        /** @var \Illuminate\Support\Collection<int, Message> $messages */
        $messages = collect([
            new UserMessage('Hello!'),
            new AssistantMessage('Hello back!'),
        ]);

        $response = new TextResponse(
            steps: collect([]),
            text: 'Hello back!',
            finishReason: FinishReason::Stop,
            toolCalls: [],
            toolResults: [
                new ToolResult('call-1', 'calendar_lookup', ['date' => '2025-01-01'], ['events' => 3]),
            ],
            usage: new Usage(5, 10),
            meta: new Meta('fake-id', 'fake-model'),
            messages: $messages,
            additionalContent: [],
        );

        Prism::fake([$response]);

        $chatThread = new ChatThreadLog;
        $request = new TextRequest(
            Prism::text()->using('fake', 'fake-model'),
            $chatThread
        );

        $ranCompletion = false;
        $request->onComplete(function () use (&$ranCompletion): void {
            $ranCompletion = true;
        });

        $resolved = $request->asText();

        $this->assertInstanceOf(TextResponse::class, $resolved);
        $this->assertSame('Hello back!', $resolved->text);
        $this->assertTrue($ranCompletion);
        $this->assertCount(2, $chatThread->messages());
        $this->assertCount(1, $chatThread->toolInvocations());
    }
}
