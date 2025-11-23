<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Services\Models\AiAssistantPromptService;
use Atlas\Nexus\Services\Models\AiAssistantService;
use Atlas\Nexus\Services\Models\AiMemoryService;
use Atlas\Nexus\Services\Models\AiMessageService;

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
        private readonly AiAssistantService $assistantService,
        private readonly AiAssistantPromptService $promptService,
        private readonly AiMessageService $messageService,
        private readonly AiMemoryService $memoryService,
    ) {}

    /**
     * Purge all supported soft-deleted Nexus models.
     *
     * @return array{messages: int, memories: int, prompts: int, assistants: int}
     */
    public function purge(?int $chunkSize = null): array
    {
        $chunkSize = $chunkSize !== null ? max(1, $chunkSize) : self::DEFAULT_CHUNK_SIZE;

        return [
            'messages' => $this->purgeService($this->messageService, $chunkSize),
            'memories' => $this->purgeService($this->memoryService, $chunkSize),
            'prompts' => $this->purgeService($this->promptService, $chunkSize),
            'assistants' => $this->purgeService($this->assistantService, $chunkSize),
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
