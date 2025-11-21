<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Models\AiMessage;

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
        /** @var AiMessage $message */
        $message = parent::create($data);

        return $message;
    }
}
