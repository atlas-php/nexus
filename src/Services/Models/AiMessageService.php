<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Models;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Models\AiToolRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Class AiMessageService
 *
 * Handles CRUD interactions for messages so threads can record conversation history consistently.
 * PRD Reference: Atlas Nexus Overview — ai_messages schema.
 *
 * @extends ModelService<AiMessage>
 */
class AiMessageService extends ModelService
{
    protected string $model = AiMessage::class;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): AiMessage
    {
        $data = $this->applyGroupId($data);

        /** @var AiMessage $message */
        $message = parent::create($data);

        return $message;
    }

    public function markStatus(AiMessage $message, AiMessageStatus $status, ?string $failedReason = null): AiMessage
    {
        /** @var AiMessage $updated */
        $updated = $this->update($message, [
            'status' => $status->value,
            'failed_reason' => $status === AiMessageStatus::FAILED ? $failedReason : null,
        ]);

        return $updated;
    }

    public function delete(Model $message, bool $force = false): bool
    {
        $messageId = $message->getKey();

        if (is_int($messageId) || is_string($messageId)) {
            DB::transaction(static function () use ($messageId): void {
                AiToolRun::query()->where('assistant_message_id', $messageId)->delete();
            });
        }

        return parent::delete($message, $force);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function applyGroupId(array $data): array
    {
        if (array_key_exists('group_id', $data)) {
            return $data;
        }

        $threadId = $data['thread_id'] ?? null;

        if (! is_int($threadId) && ! is_string($threadId)) {
            return $data;
        }

        /** @var AiThread|null $thread */
        $thread = AiThread::query()->find($threadId);

        if ($thread !== null && $thread->group_id !== null) {
            $data['group_id'] = $thread->group_id;
        }

        return $data;
    }
}
