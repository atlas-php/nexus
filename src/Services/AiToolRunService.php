<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Models\AiToolRun;

/**
 * Class AiToolRunService
 *
 * Provides CRUD utilities for tool runs and status transitions tied to assistant messages.
 * PRD Reference: Atlas Nexus Overview — ai_tool_runs schema.
 *
 * @extends ModelService<AiToolRun>
 */
class AiToolRunService extends ModelService
{
    protected string $model = AiToolRun::class;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): AiToolRun
    {
        /** @var AiToolRun $run */
        $run = parent::create($data);

        return $run;
    }

    public function markStatus(AiToolRun $run, string $status): AiToolRun
    {
        /** @var AiToolRun $updated */
        $updated = $this->update($run, ['status' => $status]);

        return $updated;
    }
}
