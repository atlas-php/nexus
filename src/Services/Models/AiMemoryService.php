<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Models;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Models\AiMemory;

/**
 * Class AiMemoryService
 *
 * Handles CRUD operations for shared memory entries and metadata updates.
 * PRD Reference: Atlas Nexus Overview — ai_memories schema.
 *
 * @extends ModelService<AiMemory>
 */
class AiMemoryService extends ModelService
{
    protected string $model = AiMemory::class;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): AiMemory
    {
        /** @var AiMemory $memory */
        $memory = parent::create($data);

        return $memory;
    }
}
