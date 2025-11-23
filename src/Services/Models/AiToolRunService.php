<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Models;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Enums\AiToolRunStatus;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Models\AiToolRun;
use Illuminate\Support\Carbon;

/**
 * Class AiToolRunService
 *
 * Provides CRUD utilities for tool runs and status transitions tied to assistant messages.
 * PRD Reference: Atlas Nexus Overview — ai_message_tools schema.
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
        $threadId = $data['thread_id'] ?? null;

        if ((is_int($threadId) || is_string($threadId)) && (! array_key_exists('group_id', $data) || ! array_key_exists('assistant_key', $data))) {
            /** @var AiThread|null $thread */
            $thread = AiThread::query()->find($threadId);

            if ($thread !== null) {
                if (! array_key_exists('group_id', $data)) {
                    $data['group_id'] = $thread->group_id;
                }

                if (! isset($data['assistant_key']) && $thread->assistant_key !== '') {
                    $data['assistant_key'] = $thread->assistant_key;
                }
            }
        }

        /** @var AiToolRun $run */
        $run = parent::create($data);

        return $run;
    }

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
