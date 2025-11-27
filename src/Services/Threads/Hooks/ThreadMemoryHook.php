<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads\Hooks;

use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Jobs\PushMemoryExtractorAssistantJob;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Models\AiThreadService;

/**
 * Class ThreadMemoryHook
 *
 * Dispatches the memory extractor agent when pending messages reach the configured threshold.
 */
class ThreadMemoryHook implements ThreadHook
{
    private const THREAD_SUMMARY_AGENT_KEY = 'thread-summary-assistant';

    private const MEMORY_AGENT_KEY = 'memory-assistant';

    public function __construct(
        private readonly AiMessageService $messageService,
        private readonly AiThreadService $threadService
    ) {}

    public function key(): string
    {
        return 'thread-memory';
    }

    public function handle(ThreadHookContext $context): void
    {
        $thread = $context->thread()->fresh();

        if ($thread === null) {
            return;
        }

        if (in_array($thread->assistant_key, [self::THREAD_SUMMARY_AGENT_KEY, self::MEMORY_AGENT_KEY], true)) {
            return;
        }

        $pendingCount = $this->messageService->query()
            ->where('thread_id', $thread->getKey())
            ->where('status', AiMessageStatus::COMPLETED->value)
            ->where('is_memory_checked', false)
            ->count();

        $threshold = $this->memoryConfig('pending_message_count', 4);

        if ($pendingCount < $threshold) {
            return;
        }

        $metadata = $thread->metadata ?? [];

        if (! empty($metadata['memory_job_pending'])) {
            return;
        }

        $metadata['memory_job_pending'] = true;

        $this->threadService->update($thread, [
            'metadata' => $metadata,
        ]);

        PushMemoryExtractorAssistantJob::dispatch($thread->getKey());
    }

    private function memoryConfig(string $key, int $default): int
    {
        $configuration = config('atlas-nexus.memory', []);

        if (! is_array($configuration)) {
            return $default;
        }

        $value = (int) ($configuration[$key] ?? $default);

        return $value > 0 ? $value : $default;
    }
}
