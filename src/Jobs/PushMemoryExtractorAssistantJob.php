<?php

declare(strict_types=1);

namespace Atlas\Nexus\Jobs;

use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Models\AiThreadService;
use Atlas\Nexus\Services\Threads\ThreadMemoryExtractionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Class PushMemoryExtractorAssistantJob
 *
 * Dispatches the memory extractor assistant for a thread once enough unchecked messages accumulate.
 */
class PushMemoryExtractorAssistantJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $threadId)
    {
        $queue = $this->resolveQueue();

        if ($queue !== null) {
            $this->onQueue($queue);
        }
    }

    public function handle(
        AiThreadService $threadService,
        AiMessageService $messageService,
        ThreadMemoryExtractionService $memoryExtractionService
    ): void {
        $thread = $threadService->find($this->threadId);

        if ($thread === null) {
            return;
        }

        $messages = $messageService->query()
            ->where('thread_id', $thread->id)
            ->where('status', AiMessageStatus::COMPLETED->value)
            ->where('is_memory_checked', false)
            ->where('is_context_prompt', false)
            ->orderBy('sequence')
            ->get();

        if ($messages->isEmpty()) {
            $this->clearPendingFlag($threadService, $thread);

            return;
        }

        try {
            $memoryExtractionService->extractFromMessages($thread, $messages);
        } finally {
            $this->clearPendingFlag($threadService, $thread->fresh() ?? $thread);
        }
    }

    protected function resolveQueue(): ?string
    {
        $queue = config('atlas-nexus.queue');

        return is_string($queue) && $queue !== '' ? $queue : null;
    }

    private function clearPendingFlag(AiThreadService $threadService, AiThread $thread): void
    {
        $metadata = $thread->metadata ?? [];

        if (! array_key_exists('memory_job_pending', $metadata)) {
            return;
        }

        unset($metadata['memory_job_pending']);

        $threadService->update($thread, [
            'metadata' => $metadata === [] ? null : $metadata,
        ]);
    }
}
