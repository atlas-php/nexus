<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Enums\AiMessageContentType;
use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Enums\AiThreadType;
use Atlas\Nexus\Jobs\RunAssistantResponseJob;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Assistants\AssistantRegistry;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Models\AiThreadService;
use Atlas\Nexus\Services\Prompts\ContextPromptService;
use Atlas\Nexus\Support\Assistants\ResolvedAssistant;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use RuntimeException;

use function trim;

/**
 * Class ThreadMessageService
 *
 * Records user messages within a thread, stages the pending assistant reply, and dispatches or runs generation inline per request.
 */
class ThreadMessageService
{
    public function __construct(
        private readonly AiMessageService $messageService,
        private readonly AiThreadService $threadService,
        private readonly AssistantResponseService $assistantResponseService,
        private readonly AssistantRegistry $assistantRegistry,
        private readonly ContextPromptService $contextPromptService,
        private readonly ConfigRepository $config
    ) {}

    /**
     * @return array{user: AiMessage, assistant: AiMessage, context_prompt: AiMessage|null}
     */
    public function sendUserMessage(
        AiThread $thread,
        string $content,
        ?int $userId = null,
        AiMessageContentType $contentType = AiMessageContentType::TEXT,
        bool $dispatchResponse = true
    ): array {
        $this->ensureAssistantIsIdle($thread);
        $assistant = $this->resolveAssistant($thread);

        $userId = $userId ?? $thread->user_id;
        $contextMessage = $this->maybeCreateContextMessage($thread, $assistant);
        $sequence = $this->nextSequence($thread);

        /** @var AiMessage $userMessage */
        $userMessage = $this->messageService->create([
            'thread_id' => $thread->id,
            'assistant_key' => $assistant->key(),
            'user_id' => $userId,
            'group_id' => $thread->group_id,
            'role' => AiMessageRole::USER->value,
            'content' => $content,
            'content_type' => $contentType->value,
            'sequence' => $sequence,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        /** @var AiMessage $assistantMessage */
        $assistantMessage = $this->messageService->create([
            'thread_id' => $thread->id,
            'assistant_key' => $assistant->key(),
            'user_id' => null,
            'group_id' => $thread->group_id,
            'role' => AiMessageRole::ASSISTANT->value,
            'content' => '',
            'content_type' => AiMessageContentType::TEXT->value,
            'sequence' => $sequence + 1,
            'status' => AiMessageStatus::PROCESSING->value,
        ]);

        $this->threadService->update($thread, [
            'last_message_at' => Carbon::now(),
        ]);

        if ($dispatchResponse) {
            $dispatch = RunAssistantResponseJob::dispatch($assistantMessage->id);
            $queue = $this->responseQueue();

            if ($queue !== null) {
                $dispatch->onQueue($queue);
            }
        } else {
            $this->assistantResponseService->handle($assistantMessage->id);
        }

        return [
            'user' => $userMessage,
            'assistant' => $assistantMessage,
            'context_prompt' => $contextMessage,
        ];
    }

    protected function ensureAssistantIsIdle(AiThread $thread): void
    {
        $isProcessing = $this->messageService->query()
            ->where('thread_id', $thread->id)
            ->where('role', AiMessageRole::ASSISTANT->value)
            ->where('status', AiMessageStatus::PROCESSING->value)
            ->exists();

        if ($isProcessing) {
            throw new RuntimeException('Assistant is still processing the previous message.');
        }
    }

    protected function resolveAssistant(AiThread $thread): ResolvedAssistant
    {
        $assistantKey = $thread->assistant_key;

        if ($assistantKey === '') {
            throw new RuntimeException('Thread is missing an associated assistant.');
        }

        return $this->assistantRegistry->require($assistantKey);
    }

    protected function nextSequence(AiThread $thread): int
    {
        $current = $this->messageService->query()
            ->where('thread_id', $thread->id)
            ->max('sequence');

        return ((int) ($current ?? 0)) + 1;
    }

    protected function responseQueue(): ?string
    {
        $queue = $this->config->get('atlas-nexus.queue');

        return is_string($queue) && $queue !== '' ? $queue : null;
    }

    protected function maybeCreateContextMessage(AiThread $thread, ResolvedAssistant $assistant): ?AiMessage
    {
        if ($this->threadHasMessages($thread) || ! $this->isUserThread($thread)) {
            return null;
        }

        $content = $this->contextPromptService->buildForThread($thread, $assistant);

        if (! is_string($content) || trim($content) === '') {
            return null;
        }

        /** @var AiMessage $message */
        $message = $this->messageService->create([
            'thread_id' => $thread->id,
            'assistant_key' => $assistant->key(),
            'user_id' => null,
            'group_id' => $thread->group_id,
            'role' => AiMessageRole::ASSISTANT->value,
            'content' => $content,
            'content_type' => AiMessageContentType::TEXT->value,
            'sequence' => $this->nextSequence($thread),
            'status' => AiMessageStatus::COMPLETED->value,
            'is_context_prompt' => true,
        ]);

        return $message;
    }

    protected function threadHasMessages(AiThread $thread): bool
    {
        return $this->messageService->query()
            ->where('thread_id', $thread->id)
            ->exists();
    }

    protected function isUserThread(AiThread $thread): bool
    {
        $typeValue = $thread->getAttribute('type');

        if ($typeValue instanceof AiThreadType) {
            $typeValue = $typeValue->value;
        } elseif (! is_string($typeValue)) {
            $typeValue = (string) $typeValue;
        }

        return $typeValue === AiThreadType::USER->value;
    }
}
