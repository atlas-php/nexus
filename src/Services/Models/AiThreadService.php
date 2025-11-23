<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Models;

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

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Model
    {
        return parent::create($this->normalizePayload($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Model $model, array $data): Model
    {
        return parent::update($model, $this->normalizePayload($data));
    }

    /**
     * Ensure summary fields stay within required limits before persistence.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizePayload(array $data): array
    {
        if (array_key_exists('summary', $data) && is_string($data['summary'])) {
            $summary = trim($data['summary']);
            $data['summary'] = $summary === '' ? null : $summary;
        }

        return $data;
    }
}
