<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Enums\AiMessageContentType;
use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Enums\AiThreadStatus;
use Atlas\Nexus\Enums\AiThreadType;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Models\AiThreadService;
use Atlas\Nexus\Support\Assistants\ResolvedAssistant;
use Atlas\Nexus\Support\Prism\TextResponseSerializer;
use Illuminate\Support\Carbon;
use Prism\Prism\Text\Response;

/**
 * Class AssistantThreadLogger
 *
 * Persists per-assistant audit threads with the payload and Prism response so every run has an inspectable transcript.
 */
class AssistantThreadLogger
{
    public function __construct(
        private readonly AiThreadService $threadService,
        private readonly AiMessageService $messageService
    ) {}

    /**
     * @param  array<string, mixed>  $threadMetadata
     * @param  array<string, mixed>  $assistantMessageMetadata
     */
    public function log(
        AiThread $parentThread,
        ResolvedAssistant $assistant,
        string $title,
        string $userMessageContent,
        Response $response,
        array $threadMetadata = [],
        array $assistantMessageMetadata = [],
        AiThreadStatus $status = AiThreadStatus::CLOSED
    ): AiThread {
        $metadata = array_merge([
            'source_thread_id' => $parentThread->getKey(),
            'assistant_key' => $assistant->key(),
            'assistant_is_hidden' => $assistant->isHidden(),
        ], $threadMetadata);

        $childThread = $this->threadService->create([
            'assistant_key' => $assistant->key(),
            'user_id' => $parentThread->user_id,
            'group_id' => $parentThread->group_id,
            'type' => $assistant->isHidden() ? AiThreadType::TOOL->value : AiThreadType::USER->value,
            'parent_thread_id' => $assistant->isHidden() ? $parentThread->getKey() : null,
            'status' => $status->value,
            'title' => $title,
            'summary' => null,
            'metadata' => $metadata,
            'last_message_at' => Carbon::now(),
        ]);

        $this->messageService->create([
            'thread_id' => $childThread->id,
            'assistant_key' => $assistant->key(),
            'user_id' => $parentThread->user_id,
            'group_id' => $parentThread->group_id,
            'role' => AiMessageRole::USER->value,
            'content' => $userMessageContent,
            'content_type' => AiMessageContentType::TEXT->value,
            'sequence' => 1,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        $this->messageService->create([
            'thread_id' => $childThread->id,
            'assistant_key' => $assistant->key(),
            'user_id' => null,
            'group_id' => $parentThread->group_id,
            'role' => AiMessageRole::ASSISTANT->value,
            'content' => $response->text,
            'content_type' => AiMessageContentType::TEXT->value,
            'sequence' => 2,
            'status' => AiMessageStatus::COMPLETED->value,
            'model' => $response->meta->model ?? $assistant->model(),
            'tokens_in' => $response->usage->promptTokens,
            'tokens_out' => $response->usage->completionTokens,
            'provider_response_id' => $response->meta->id ?? null,
            'raw_response' => TextResponseSerializer::serialize($response),
            'metadata' => $assistantMessageMetadata === [] ? null : $assistantMessageMetadata,
        ]);

        return $childThread;
    }
}
