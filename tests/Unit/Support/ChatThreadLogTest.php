<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Support;

use Atlas\Nexus\Services\Threads\Logging\ChatThreadLog;
use Atlas\Nexus\Tests\TestCase;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolResult;

use function collect;

/**
 * Class ChatThreadLogTest
 *
 * Verifies chat thread logs capture user, assistant, and tool usage details from Prism responses.
 */
class ChatThreadLogTest extends TestCase
{
    public function test_it_records_messages_and_tool_results(): void
    {
        $log = new ChatThreadLog;

        /** @var \Illuminate\Support\Collection<int, Message> $messages */
        $messages = collect([
            new UserMessage('Hello there'),
            new AssistantMessage('Hi back at you'),
        ]);

        $log->recordFromResponse($messages, [
            new ToolResult('call-1', 'calendar_lookup', ['date' => '2025-01-01'], ['events' => 2]),
        ]);

        $this->assertCount(2, $log->messages());
        $this->assertSame('user', $log->messages()[0]->role());
        $this->assertSame('Hello there', $log->messages()[0]->content());
        $this->assertSame('assistant', $log->messages()[1]->role());
        $this->assertSame('Hi back at you', $log->messages()[1]->content());

        $this->assertCount(1, $log->toolInvocations());
        $this->assertSame('calendar_lookup', $log->toolInvocations()[0]->toolName());
        $this->assertSame(['date' => '2025-01-01'], $log->toolInvocations()[0]->arguments());
        $this->assertSame(['events' => 2], $log->toolInvocations()[0]->result());
    }
}
