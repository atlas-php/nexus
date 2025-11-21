<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Models\AiToolRun;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Class AiThreadService
 *
 * Coordinates CRUD operations for chat threads, allowing status updates and metadata changes.
 * PRD Reference: Atlas Nexus Overview — ai_threads schema.
 *
 * @extends ModelService<AiThread>
 */
class AiThreadService extends ModelService
{
    protected string $model = AiThread::class;

    public function delete(Model $thread, bool $force = false): bool
    {
        $threadId = $thread->getKey();

        if (is_int($threadId) || is_string($threadId)) {
            DB::transaction(static function () use ($threadId): void {
                AiToolRun::query()->where('thread_id', $threadId)->delete();
                AiMessage::query()->where('thread_id', $threadId)->delete();
                AiMemory::query()->where('thread_id', $threadId)->delete();
            });
        }

        return parent::delete($thread, $force);
    }
}
