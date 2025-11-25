<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads\Logging;

use Illuminate\Support\Collection;
use Prism\Prism\Contracts\Message;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolResult;

/**
 * Class ChatThreadLog
 *
 * Tracks user and assistant messages alongside tool invocations for a single chat thread.
 */
class ChatThreadLog
{
    /**
     * @var array<int, ChatMessage>
     */
    protected array $messages = [];

    /**
     * @var array<int, ToolInvocation>
     */
    protected array $toolInvocations = [];

    public function recordUserMessage(string $content): void
    {
        $this->messages[] = new ChatMessage('user', $content);
    }

    public function recordAssistantMessage(string $content): void
    {
        $this->messages[] = new ChatMessage('assistant', $content);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>|int|float|string|null  $result
     */
    public function recordToolInvocation(string $toolName, array $arguments, int|float|string|array|null $result): void
    {
        $this->toolInvocations[] = new ToolInvocation($toolName, $arguments, $result);
    }

    /**
     * @param  Collection<int, Message>  $messages
     * @param  array<int, ToolResult>  $toolResults
     */
    public function recordFromResponse(Collection $messages, array $toolResults = []): void
    {
        $this->recordMessages($messages);
        $this->recordToolResults($toolResults);
    }

    /**
     * @return array<int, ChatMessage>
     */
    public function messages(): array
    {
        return $this->messages;
    }

    /**
     * @return array<int, ToolInvocation>
     */
    public function toolInvocations(): array
    {
        return $this->toolInvocations;
    }

    /**
     * @param  Collection<int, Message>  $messages
     */
    protected function recordMessages(Collection $messages): void
    {
        $messages->each(function (Message $message): void {
            if ($message instanceof UserMessage) {
                $this->recordUserMessage($message->text());

                return;
            }

            if ($message instanceof AssistantMessage && $message->content !== '') {
                $this->recordAssistantMessage($message->content);

                return;
            }

            if ($message instanceof ToolResultMessage) {
                return;
            }
        });
    }

    /**
     * @param  ToolResult[]  $toolResults
     */
    protected function recordToolResults(array $toolResults): void
    {
        foreach ($toolResults as $result) {
            $this->recordToolInvocation($result->toolName, $result->args, $result->result);
        }
    }
}
