<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services;

use Atlas\Core\Services\ModelService;
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

    public function markStatus(AiToolRun $run, string $status): AiToolRun
    {
        $updates = ['status' => $status];

        if ($status === 'running' && $run->started_at === null) {
            $updates['started_at'] = Carbon::now();
        }

        if (in_array($status, ['succeeded', 'failed'], true)) {
            $updates['finished_at'] = Carbon::now();
        }

        /** @var AiToolRun $updated */
        $updated = $this->update($run, $updates);

        return $updated;
    }
}
