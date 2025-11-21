<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiAssistantTool;
use Atlas\Nexus\Models\AiTool;

/**
 * Class AiAssistantService
 *
 * Provides CRUD helpers for AI assistants and manages tool associations and prompt linkage.
 * PRD Reference: Atlas Nexus Overview — ai_assistants and ai_assistant_tool schemas.
 *
 * @extends ModelService<AiAssistant>
 */
class AiAssistantService extends ModelService
{
    protected string $model = AiAssistant::class;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): AiAssistant
    {
        /** @var AiAssistant $assistant */
        $assistant = parent::create($data);

        return $assistant;
    }

    /**
     * Attach or update tool configuration for an assistant.
     *
     * @param  array<string, mixed>  $config
     */
    public function attachTool(AiAssistant $assistant, AiTool $tool, array $config = []): AiAssistantTool
    {
        /** @var AiAssistantTool $mapping */
        $mapping = AiAssistantTool::query()->updateOrCreate(
            [
                'assistant_id' => $assistant->id,
                'tool_id' => $tool->id,
            ],
            [
                'config' => $config ?: null,
            ]
        );

        return $mapping;
    }

    public function detachTool(AiAssistant $assistant, AiTool $tool): bool
    {
        return AiAssistantTool::query()
            ->where([
                'assistant_id' => $assistant->id,
                'tool_id' => $tool->id,
            ])
            ->delete() > 0;
    }
}
