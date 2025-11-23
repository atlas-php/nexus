<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Models\AiThreadService;
use Atlas\Nexus\Support\Chat\ThreadState;
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
        $searchQueries = $this->normalizeSearchQueries($options['search'] ?? null);
        $userNameSearch = $this->isUserNameSearch($searchQueries, $state);
        $threadsTable = $this->threadTable();
        $messagesTable = $this->messageTable();

        $query = $this->threadService->query()
            ->select([
                "{$threadsTable}.id",
                "{$threadsTable}.title",
                "{$threadsTable}.summary",
                "{$threadsTable}.metadata",
                "{$threadsTable}.last_message_at",
                "{$threadsTable}.created_at",
                "{$threadsTable}.updated_at",
            ])
            ->where("{$threadsTable}.assistant_key", $state->assistant->key())
            ->where("{$threadsTable}.user_id", $state->thread->user_id)
            ->where("{$threadsTable}.id", '!=', $state->thread->getKey())
            ->orderByRaw("COALESCE({$threadsTable}.last_message_at, {$threadsTable}.updated_at, {$threadsTable}.created_at) DESC");

        if ($searchQueries !== [] && ! $userNameSearch) {
            $query->where(function (Builder $builder) use ($threadsTable, $messagesTable, $searchQueries): void {
                foreach ($searchQueries as $queryTerm) {
                    $likeValue = '%'.$queryTerm.'%';

                    $builder->orWhere(function (Builder $clause) use ($threadsTable, $messagesTable, $likeValue): void {
                        $clause
                            ->where("{$threadsTable}.title", 'like', $likeValue)
                            ->orWhere("{$threadsTable}.summary", 'like', $likeValue)
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
        $paginator = $query->paginate(self::PER_PAGE);

        return $paginator;
    }

    public function fetchThread(ThreadState $state, int $threadId): AiThread
    {
        $threads = $this->fetchThreads($state, [$threadId]);

        if ($threads === []) {
            throw new RuntimeException('Thread not found for this assistant and user.');
        }

        /** @var AiThread $thread */
        $thread = reset($threads);

        return $thread;
    }

    /**
     * Fetch multiple threads scoped to the assistant/user ordering results per request.
     *
     * @param  array<int, int>  $threadIds
     * @return array<int, AiThread>
     */
    public function fetchThreads(ThreadState $state, array $threadIds): array
    {
        $normalizedIds = $this->normalizeThreadIds($threadIds);

        if ($normalizedIds === []) {
            throw new RuntimeException('Provide at least one thread_id to fetch.');
        }

        $threads = $this->threadService->query()
            ->where('assistant_key', $state->assistant->key())
            ->where('user_id', $state->thread->user_id)
            ->whereIn('id', $normalizedIds)
            ->with([
                'messages' => function ($query): void {
                    $query->orderBy('sequence');
                },
            ])
            ->orderBy('id')
            ->get()
            ->keyBy('id');

        $ordered = [];

        foreach ($normalizedIds as $threadId) {
            /** @var AiThread|null $thread */
            $thread = $threads->get($threadId);

            if ($thread === null) {
                throw new RuntimeException('Thread not found for this assistant and user.');
            }

            $ordered[] = $thread;
        }

        return $ordered;
    }

    /**
     * @return array{thread: AiThread, title: string|null, summary: string|null, keywords: array<int, string>}
     */
    public function updateThread(
        ThreadState $state,
        int $threadId,
        ?string $title,
        ?string $summary,
        bool $generate
    ): array {
        $thread = $this->threadService->query()
            ->where('assistant_key', $state->assistant->key())
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

        if ($title === null && $summary === null) {
            throw new RuntimeException('Provide a title or summary to update the thread.');
        }

        $updated = $this->titleSummaryService->apply($targetState, $title, $summary);

        return [
            'thread' => $updated,
            'title' => $updated->title,
            'summary' => $updated->summary,
            'keywords' => $this->keywordsForThread($updated),
        ];
    }

    protected function threadTable(): string
    {
        return config('atlas-nexus.database.tables.ai_threads', 'ai_threads');
    }

    protected function messageTable(): string
    {
        return config('atlas-nexus.database.tables.ai_messages', 'ai_messages');
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeSearchQueries(mixed $value): array
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

    /**
     * @param  array<int, string>  $queries
     */
    protected function isUserNameSearch(array $queries, ThreadState $state): bool
    {
        if ($queries === []) {
            return false;
        }

        $userName = $this->userName($state);

        if ($userName === null) {
            return false;
        }

        $normalizedUser = mb_strtolower($userName);

        foreach ($queries as $term) {
            $normalizedTerm = mb_strtolower($term);

            if ($normalizedTerm === '') {
                continue;
            }

            if (str_contains($normalizedUser, $normalizedTerm) || str_contains($normalizedTerm, $normalizedUser)) {
                return true;
            }
        }

        return false;
    }

    protected function userName(ThreadState $state): ?string
    {
        try {
            $user = $state->thread->user;
        } catch (Throwable) {
            return null;
        }

        if ($user === null) {
            return null;
        }

        $name = $user->getAttribute('name');

        if (! is_string($name)) {
            return null;
        }

        $trimmed = trim($name);

        return $trimmed === '' ? null : $trimmed;
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

    /**
     * @param  array<int, mixed>  $threadIds
     * @return array<int, int>
     */
    protected function normalizeThreadIds(array $threadIds): array
    {
        $normalized = [];

        foreach ($threadIds as $value) {
            if (is_int($value)) {
                $id = $value;
            } elseif (is_numeric($value)) {
                $id = (int) $value;
            } else {
                continue;
            }

            if ($id > 0) {
                $normalized[] = $id;
            }
        }

        return array_values(array_unique($normalized));
    }
}
