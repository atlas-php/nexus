<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Models\AiThreadService;
use Atlas\Nexus\Services\Models\AiToolRunService;

/**
 * Class NexusPurgeService
 *
 * Permanently removes soft-deleted Nexus records in controlled chunks so related data
 * (like tool runs cascading from messages) routes through the existing model services.
 */
class NexusPurgeService
{
    public const DEFAULT_CHUNK_SIZE = 100;

    public function __construct(
        private readonly AiThreadService $threadService,
        private readonly AiMessageService $messageService,
        private readonly AiToolRunService $toolRunService,
    ) {}

    /**
     * Purge all supported soft-deleted Nexus models.
     *
     * @return array{tool_runs: int, messages: int, threads: int}
     */
    public function purge(?int $chunkSize = null): array
    {
        $chunkSize = $chunkSize !== null ? max(1, $chunkSize) : self::DEFAULT_CHUNK_SIZE;

        return [
            'tool_runs' => $this->purgeService($this->toolRunService, $chunkSize),
            'messages' => $this->purgeService($this->messageService, $chunkSize),
            'threads' => $this->purgeService($this->threadService, $chunkSize),
        ];
    }

    /**
     * @template TModel of \Illuminate\Database\Eloquent\Model
     *
     * @param  ModelService<TModel>  $service
     */
    protected function purgeService(ModelService $service, int $chunkSize): int
    {
        $purged = 0;

        $service->query()
            ->whereNotNull('deleted_at')
            ->orderBy('id')
            ->chunkById($chunkSize, function (iterable $records) use ($service, &$purged): void {
                foreach ($records as $record) {
                    $service->delete($record, true);
                    $purged++;
                }
            });

        return $purged;
    }
}
