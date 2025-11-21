<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Models;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Models\AiMessage;
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
}
