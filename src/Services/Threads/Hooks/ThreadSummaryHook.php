<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads\Hooks;

use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Jobs\PushThreadSummaryAssistantJob;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Models\AiMessageService;

/**
 * Class ThreadSummaryHook
 *
 * Dispatches the thread summary agent based on configurable message thresholds.
 */
class ThreadSummaryHook implements ThreadHook
{
    private const THREAD_SUMMARY_AGENT_KEY = 'thread-summary-assistant';

    public function __construct(private readonly AiMessageService $messageService) {}

    public function key(): string
    {
        return 'thread-summary';
    }

    public function handle(ThreadHookContext $context): void
    {
        $thread = $context->thread()->fresh();

        if ($thread === null) {
            return;
        }

        $target = $this->resolveTargetThread($thread);

        if ($target === null) {
            return;
        }

        $minimumMessages = $this->threadSummaryConfig('minimum_messages', 2);

        $totalMessageCount = $this->messageService->query()
            ->where('thread_id', $target->getKey())
            ->where('status', AiMessageStatus::COMPLETED->value)
            ->count();

        if ($totalMessageCount < $minimumMessages) {
            return;
        }

        if ($target->last_summary_message_id === null) {
            PushThreadSummaryAssistantJob::dispatch($target->getKey());

            return;
        }

        $messageInterval = $this->threadSummaryConfig('message_interval', 10);

        $messagesSinceSummary = $this->messageService->query()
            ->where('thread_id', $target->getKey())
            ->where('status', AiMessageStatus::COMPLETED->value)
            ->where('id', '>', $target->last_summary_message_id)
            ->count();

        if ($messagesSinceSummary >= $messageInterval) {
            PushThreadSummaryAssistantJob::dispatch($target->getKey());
        }
    }

    private function resolveTargetThread(AiThread $thread): ?AiThread
    {
        if ($thread->assistant_key !== self::THREAD_SUMMARY_AGENT_KEY) {
            return $thread;
        }

        $parent = $thread->parentThread()->first();

        if (! $parent instanceof AiThread) {
            return null;
        }

        return $parent->fresh() ?? $parent;
    }

    private function threadSummaryConfig(string $key, int $default): int
    {
        $configuration = config('atlas-nexus.thread_summary', []);

        if (! is_array($configuration)) {
            return $default;
        }

        $value = (int) ($configuration[$key] ?? $default);

        return $value > 0 ? $value : $default;
    }
}
