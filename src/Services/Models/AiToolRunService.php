<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Models;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Enums\AiToolRunStatus;
use Atlas\Nexus\Models\AiToolRun;
use Illuminate\Support\Carbon;

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

    public function markStatus(AiToolRun $run, AiToolRunStatus $status): AiToolRun
    {
        $updates = ['status' => $status];

        if ($status === AiToolRunStatus::RUNNING && $run->started_at === null) {
            $updates['started_at'] = Carbon::now();
        }

        if (in_array($status, [AiToolRunStatus::SUCCEEDED, AiToolRunStatus::FAILED], true)) {
            $updates['finished_at'] = Carbon::now();
        }

        /** @var AiToolRun $updated */
        $updated = $this->update($run, array_map(
            static fn ($value) => $value instanceof AiToolRunStatus ? $value->value : $value,
            $updates
        ));

        return $updated;
    }
}
