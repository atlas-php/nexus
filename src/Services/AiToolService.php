<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Models\AiAssistantTool;
use Atlas\Nexus\Models\AiTool;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Class AiToolService
 *
 * Provides CRUD operations for tool registrations, enabling activation and handler updates.
 * PRD Reference: Atlas Nexus Overview — ai_tools schema.
 *
 * @extends ModelService<AiTool>
 */
class AiToolService extends ModelService
{
    protected string $model = AiTool::class;

    public function delete(Model $tool, bool $force = false): bool
    {
        $toolId = $tool->getKey();

        if (is_int($toolId) || is_string($toolId)) {
            DB::transaction(static function () use ($toolId): void {
                AiAssistantTool::query()->where('tool_id', $toolId)->delete();
            });
        }

        return parent::delete($tool, $force);
    }
}
