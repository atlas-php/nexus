<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Models\AiThreadService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Class ThreadMemoryService
 *
 * Manages thread-scoped memory storage using the ai_threads.memories column and exposes helpers to read
 * merged user memories across all threads.
 */
class ThreadMemoryService
{
    public function __construct(private readonly AiThreadService $threadService) {}

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function memoriesForThread(AiThread $thread): Collection
    {
        return collect($this->normalizeEntries($thread, $thread->memories ?? []));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function userMemories(int $userId): Collection
    {
        $threads = AiThread::query()
            ->where('user_id', $userId)
            ->whereNotNull('memories')
            ->get(['id', 'memories']);

        return $threads
            ->flatMap(function (AiThread $thread): array {
                return $this->normalizeEntries($thread, $thread->memories ?? []);
            })
            ->values();
    }

    /**
     * @param  array<int, array<string, mixed>>  $memories
     */
    public function appendMemories(AiThread $thread, array $memories): AiThread
    {
        $existing = $this->memoriesForThread($thread)->all();
        $index = $this->indexByContent($existing);
        $hasChanges = false;

        foreach ($memories as $memory) {
            $content = $this->stringValue($memory['content'] ?? null);

            if ($content === null) {
                continue;
            }

            $normalized = $this->normalizeContent($content);

            if ($normalized === null || isset($index[$normalized])) {
                continue;
            }

            $entry = [
                'content' => $content,
                'thread_id' => $thread->getKey(),
                'source_message_ids' => $this->normalizeMessageIds($memory['source_message_ids'] ?? []),
                'created_at' => Carbon::now()->toAtomString(),
            ];

            $existing[] = $entry;
            $index[$normalized] = true;
            $hasChanges = true;
        }

        if (! $hasChanges) {
            return $thread;
        }

        return $this->threadService->update($thread, [
            'memories' => array_values($existing),
        ]);
    }

    /**
     * @param  array<int, array<string, mixed>>|null  $entries
     * @return array<int, array<string, mixed>>
     */
    private function normalizeEntries(AiThread $thread, ?array $entries): array
    {
        if (! is_array($entries) || $entries === []) {
            return [];
        }

        $normalized = [];
        $seen = [];

        foreach ($entries as $entry) {
            $content = $this->stringValue($entry['content'] ?? null);

            if ($content === null) {
                continue;
            }

            $normalizedKey = $this->normalizeContent($content);

            if ($normalizedKey === null || isset($seen[$normalizedKey])) {
                continue;
            }

            $target = [
                'content' => $content,
                'thread_id' => $thread->getKey(),
                'source_message_ids' => $this->normalizeMessageIds($entry['source_message_ids'] ?? []),
                'created_at' => $this->stringValue($entry['created_at'] ?? null) ?? Carbon::now()->toAtomString(),
            ];

            $normalized[] = $target;
            $seen[$normalizedKey] = true;
        }

        return $normalized;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<string, bool>
     */
    private function indexByContent(array $entries): array
    {
        $index = [];

        foreach ($entries as $entry) {
            $hash = $this->normalizeContent($entry['content'] ?? null);

            if ($hash !== null) {
                $index[$hash] = true;
            }
        }

        return $index;
    }

    private function normalizeContent(mixed $content): ?string
    {
        $stringValue = $this->stringValue($content);

        if ($stringValue === null) {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', $stringValue);
        $trimmed = trim($collapsed ?? $stringValue);

        return $trimmed === '' ? null : mb_strtolower($trimmed);
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  mixed  $ids
     * @return array<int, int>
     */
    private function normalizeMessageIds($ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        $normalized = [];

        foreach ($ids as $id) {
            if (is_int($id)) {
                $normalized[] = $id;
            } elseif (is_string($id) && ctype_digit($id)) {
                $normalized[] = (int) $id;
            }
        }

        return array_values(array_unique($normalized));
    }
}
