<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiAssistantTool;
use Atlas\Nexus\Models\AiTool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

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

    public function delete(Model $assistant, bool $force = false): bool
    {
        $assistantId = $assistant->getKey();

        if (is_int($assistantId) || is_string($assistantId)) {
            DB::transaction(static function () use ($assistantId): void {
                AiAssistantTool::query()->where('assistant_id', $assistantId)->delete();
            });
        }

        return parent::delete($assistant, $force);
    }
}
