<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Models\AiThread;

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

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): AiThread
    {
        /** @var AiThread $thread */
        $thread = parent::create($data);

        return $thread;
    }
}
