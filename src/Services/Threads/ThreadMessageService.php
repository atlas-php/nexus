<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Enums\AiMessageContentType;
use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Jobs\RunAssistantResponseJob;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Models\AiThreadService;
use Illuminate\Support\Carbon;

/**
 * Class ThreadMessageService
 *
 * Records user messages within a thread, stages the pending assistant reply, and dispatches generation jobs.
 */
class ThreadMessageService
{
    public function __construct(
        private readonly AiMessageService $messageService,
        private readonly AiThreadService $threadService
    ) {}

    /**
     * @return array{user: AiMessage, assistant: AiMessage}
     */
    public function sendUserMessage(
        AiThread $thread,
        string $content,
        ?int $userId = null,
        AiMessageContentType $contentType = AiMessageContentType::TEXT
    ): array {
        $userId = $userId ?? $thread->user_id;
        $sequence = $this->nextSequence($thread);

        /** @var AiMessage $userMessage */
        $userMessage = $this->messageService->create([
            'thread_id' => $thread->id,
            'user_id' => $userId,
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
            'role' => AiMessageRole::ASSISTANT->value,
            'content' => '',
            'content_type' => AiMessageContentType::TEXT->value,
            'sequence' => $sequence + 1,
            'status' => AiMessageStatus::PROCESSING->value,
        ]);

        $this->threadService->update($thread, [
            'last_message_at' => Carbon::now(),
        ]);

        RunAssistantResponseJob::dispatch($assistantMessage->id);

        return [
            'user' => $userMessage,
            'assistant' => $assistantMessage,
        ];
    }

    protected function nextSequence(AiThread $thread): int
    {
        $current = $this->messageService->query()
            ->where('thread_id', $thread->id)
            ->max('sequence');

        return ((int) ($current ?? 0)) + 1;
    }
}
