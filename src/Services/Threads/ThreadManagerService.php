<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Models\AiThreadService;
use Atlas\Nexus\Support\Chat\ThreadState;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use RuntimeException;
use Throwable;

/**
 * Class ThreadManagerService
 *
 * Provides discovery, inspection, and metadata updates for assistant threads scoped to the current user.
 */
class ThreadManagerService
{
    private const PER_PAGE = 10;

    public function __construct(
        private readonly AiThreadService $threadService,
        private readonly ThreadStateService $threadStateService,
        private readonly ThreadTitleSummaryService $titleSummaryService
    ) {}

    /**
     * Fetch a paginated list of threads for the assistant/user, excluding the active thread.
     *
     * @param  array<string, mixed>  $options
     * @return LengthAwarePaginator<int, AiThread>
     */
    public function listThreads(ThreadState $state, array $options = []): LengthAwarePaginator
    {
        $page = $this->normalizePage($options['page'] ?? null);
        $searchTerms = $this->normalizeSearchTerms($options['search'] ?? null);
        [$startDate, $endDate] = $this->normalizeDateRange($options['between_dates'] ?? null);
        $threadsTable = $this->threadTable();
        $messagesTable = $this->messageTable();

        $query = $this->threadService->query()
            ->select([
                "{$threadsTable}.id",
                "{$threadsTable}.title",
                "{$threadsTable}.summary",
                "{$threadsTable}.long_summary",
                "{$threadsTable}.metadata",
                "{$threadsTable}.last_message_at",
                "{$threadsTable}.created_at",
                "{$threadsTable}.updated_at",
            ])
            ->where("{$threadsTable}.assistant_id", $state->assistant->getKey())
            ->where("{$threadsTable}.user_id", $state->thread->user_id)
            ->where("{$threadsTable}.id", '!=', $state->thread->getKey())
            ->orderByRaw("COALESCE({$threadsTable}.last_message_at, {$threadsTable}.updated_at, {$threadsTable}.created_at) DESC");

        if ($startDate !== null) {
            $query->whereDate("{$threadsTable}.created_at", '>=', $startDate);
        }

        if ($endDate !== null) {
            $query->whereDate("{$threadsTable}.created_at", '<=', $endDate);
        }

        if ($searchTerms !== []) {
            $query->where(function (Builder $builder) use ($threadsTable, $messagesTable, $searchTerms): void {
                foreach ($searchTerms as $term) {
                    $likeValue = '%'.$term.'%';

                    $builder->orWhere(function (Builder $clause) use ($threadsTable, $messagesTable, $likeValue): void {
                        $clause
                            ->where("{$threadsTable}.title", 'like', $likeValue)
                            ->orWhere("{$threadsTable}.summary", 'like', $likeValue)
                            ->orWhere("{$threadsTable}.long_summary", 'like', $likeValue)
                            ->orWhereRaw("COALESCE(JSON_EXTRACT({$threadsTable}.metadata, '$.summary_keywords'), '') LIKE ?", [$likeValue])
                            ->orWhereExists(function (QueryBuilder $messageQuery) use ($messagesTable, $threadsTable, $likeValue): void {
                                $messageQuery->selectRaw('1')
                                    ->from($messagesTable)
                                    ->whereColumn("{$messagesTable}.thread_id", "{$threadsTable}.id")
                                    ->where("{$messagesTable}.content", 'like', $likeValue);
                            });
                    });
                }
            });
        }

        /** @var LengthAwarePaginator<int, AiThread> $paginator */
        $paginator = $query->paginate(self::PER_PAGE, ['*'], 'page', $page);

        return $paginator;
    }

    public function fetchThread(ThreadState $state, int $threadId): AiThread
    {
        $thread = $this->threadService->query()
            ->where('assistant_id', $state->assistant->getKey())
            ->where('user_id', $state->thread->user_id)
            ->where('id', $threadId)
            ->with([
                'messages' => function ($query): void {
                    $query->orderBy('sequence');
                },
            ])
            ->first();

        if ($thread === null) {
            throw new RuntimeException('Thread not found for this assistant and user.');
        }

        return $thread;
    }

    /**
     * @return array{thread: AiThread, title: string|null, summary: string|null, long_summary: string|null, keywords: array<int, string>}
     */
    public function updateThread(
        ThreadState $state,
        int $threadId,
        ?string $title,
        ?string $summary,
        ?string $longSummary,
        bool $generate
    ): array {
        $thread = $this->threadService->query()
            ->where('assistant_id', $state->assistant->getKey())
            ->where('user_id', $state->thread->user_id)
            ->where('id', $threadId)
            ->first();

        if ($thread === null) {
            throw new RuntimeException('Thread not found for this assistant and user.');
        }

        $targetState = $thread->is($state->thread)
            ? $state
            : $this->threadStateService->forThread($thread);

        if ($generate) {
            return $this->titleSummaryService->generateAndSave($targetState);
        }

        if ($title === null && $summary === null && $longSummary === null) {
            throw new RuntimeException('Provide a title, short summary, or long summary to update the thread.');
        }

        $updated = $this->titleSummaryService->apply($targetState, $title, $summary, $longSummary);

        return [
            'thread' => $updated,
            'title' => $updated->title,
            'summary' => $updated->summary,
            'long_summary' => $updated->long_summary,
            'keywords' => $this->keywordsForThread($updated),
        ];
    }

    protected function threadTable(): string
    {
        return config('atlas-nexus.tables.ai_threads', 'ai_threads');
    }

    protected function messageTable(): string
    {
        return config('atlas-nexus.tables.ai_messages', 'ai_messages');
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeSearchTerms(mixed $value): array
    {
        $terms = [];

        if (is_string($value)) {
            $terms[] = $value;
        } elseif (is_array($value)) {
            $terms = $value;
        } else {
            return [];
        }

        $normalized = [];

        foreach ($terms as $term) {
            if (! is_string($term)) {
                continue;
            }

            $trimmed = trim($term);

            if ($trimmed === '') {
                continue;
            }

            $normalized[] = $trimmed;
        }

        return array_values(array_unique($normalized));
    }

    protected function normalizePage(mixed $page): int
    {
        if (is_numeric($page)) {
            $page = (int) $page;
        } else {
            $page = 1;
        }

        return max(1, $page);
    }

    /**
     * @return array{0: ?CarbonImmutable, 1: ?CarbonImmutable}
     */
    protected function normalizeDateRange(mixed $value): array
    {
        if (! is_array($value) || $value === []) {
            return [null, null];
        }

        $start = $value['start'] ?? $value[0] ?? null;
        $end = $value['end'] ?? $value[1] ?? null;

        return [
            $this->parseDate($start),
            $this->parseDate($end),
        ];
    }

    protected function parseDate(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        if ($trimmed === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($trimmed);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * @return array<int, string>
     */
    public function keywordsForThread(AiThread $thread): array
    {
        /** @var array<string, mixed> $metadata */
        $metadata = $thread->metadata ?? [];

        $keywords = $metadata['summary_keywords'] ?? [];

        if (! is_array($keywords)) {
            return [];
        }

        $normalized = [];

        foreach ($keywords as $keyword) {
            if (! is_string($keyword)) {
                continue;
            }

            $trimmed = trim($keyword);

            if ($trimmed === '') {
                continue;
            }

            $normalized[] = $trimmed;
        }

        return array_slice($normalized, 0, 12);
    }
}
