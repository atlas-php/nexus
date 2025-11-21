<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Enums\AiMessageContentType;
use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Jobs\RunAssistantResponseJob;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Models\AiThreadService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Carbon;
use RuntimeException;

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
        private readonly ConfigRepository $config
    ) {}

    /**
     * @return array{user: AiMessage, assistant: AiMessage}
     */
    public function sendUserMessage(
        AiThread $thread,
        string $content,
        ?int $userId = null,
        AiMessageContentType $contentType = AiMessageContentType::TEXT,
        bool $dispatchResponse = true
    ): array {
        $this->ensureAssistantIsIdle($thread);
        $this->resolveAssistant($thread);

        $userId = $userId ?? $thread->user_id;
        $sequence = $this->nextSequence($thread);

        /** @var AiMessage $userMessage */
        $userMessage = $this->messageService->create([
            'thread_id' => $thread->id,
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

    protected function resolveAssistant(AiThread $thread): AiAssistant
    {
        $thread->loadMissing('assistant');

        if ($thread->assistant === null) {
            throw new RuntimeException('Thread is missing an associated assistant.');
        }

        return $thread->assistant;
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
        $queue = $this->config->get('atlas-nexus.responses.queue');

        return is_string($queue) && $queue !== '' ? $queue : null;
    }
}
