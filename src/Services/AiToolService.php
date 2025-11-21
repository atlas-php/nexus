<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Models\AiTool;

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

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): AiTool
    {
        /** @var AiTool $tool */
        $tool = parent::create($data);

        return $tool;
    }
}
