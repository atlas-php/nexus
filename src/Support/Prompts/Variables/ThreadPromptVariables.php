<?php

declare(strict_types=1);

namespace Atlas\Nexus\Support\Prompts\Variables;

use Atlas\Nexus\Contracts\PromptVariableGroup;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Models\AiThreadService;
use Atlas\Nexus\Support\Prompts\PromptVariableContext;
use Illuminate\Support\Carbon;

/**
 * Class ThreadPromptVariables
 *
 * Exposes the current thread identifiers and summaries plus a UTC timestamp placeholder for prompts.
 */
class ThreadPromptVariables implements PromptVariableGroup
{
    public function __construct(
        private readonly AiThreadService $threadService
    ) {}

    /**
     * @return array<string, string|null>
     */
    public function variables(PromptVariableContext $context): array
    {
        $thread = $context->thread();

        return [
            'THREAD.ID' => (string) $thread->getKey(),
            'THREAD.TITLE' => $this->normalizeValue($thread->title),
            'THREAD.SUMMARY' => $this->normalizeValue($thread->summary),
            'THREAD.LONG_SUMMARY' => $this->normalizeValue($thread->long_summary),
            'THREAD.RECENT.IDS' => $this->recentThreadIds($thread),
            'DATETIME' => Carbon::now('UTC')->toIso8601String(),
        ];
    }

    private function normalizeValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if (trim($value) === '') {
            return null;
        }

        return $value;
    }

    private function recentThreadIds(AiThread $thread): string
    {
        $ids = $this->threadService->query()
            ->select(['id', 'last_message_at', 'updated_at', 'created_at'])
            ->where('assistant_id', $thread->assistant_id)
            ->where('user_id', $thread->user_id)
            ->where('id', '!=', $thread->getKey())
            ->orderByRaw('COALESCE(last_message_at, updated_at, created_at) DESC')
            ->limit(5)
            ->pluck('id')
            ->map(static fn ($id): string => (string) $id)
            ->all();

        if ($ids === []) {
            return 'None';
        }

        return implode(', ', $ids);
    }
}
