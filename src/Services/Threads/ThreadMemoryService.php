<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Models\AiMemoryService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Carbon;

/**
 * Class ThreadMemoryService
 *
 * Manages assistant memory storage using the dedicated ai_memories table and exposes helpers to read
 * merged user memories across threads for a given assistant.
 */
class ThreadMemoryService
{
    public function __construct(private readonly AiMemoryService $memoryService) {}

    /**
     * @return EloquentCollection<int, AiMemory>
     */
    public function memoriesForThread(AiThread $thread): EloquentCollection
    {
        /** @var EloquentCollection<int, AiMemory> $memories */
        $memories = $this->memoryService->query()
            ->where('thread_id', $thread->getKey())
            ->orderBy('created_at')
            ->get();

        return $memories;
    }

    /**
     * @return EloquentCollection<int, AiMemory>
     */
    public function userMemories(int $userId, ?string $assistantId = null): EloquentCollection
    {
        $query = $this->memoryService->query()
            ->where('user_id', $userId)
            ->when($assistantId !== null, static function ($builder) use ($assistantId): void {
                $builder->where('assistant_key', $assistantId);
            })
            ->orderBy('created_at');

        /** @var EloquentCollection<int, AiMemory> $memories */
        $memories = $query->get();

        $sorted = $memories->sort(function (AiMemory $a, AiMemory $b): int {
            $scoreA = $this->effectiveImportance($a);
            $scoreB = $this->effectiveImportance($b);

            if ($scoreA === $scoreB) {
                $timeA = $a->created_at?->getTimestamp() ?? 0;
                $timeB = $b->created_at?->getTimestamp() ?? 0;

                return $timeB <=> $timeA;
            }

            return $scoreB <=> $scoreA;
        })->values();

        return new EloquentCollection($sorted->all());
    }

    /**
     * @param  array<int, array<string, mixed>>  $memories
     * @return EloquentCollection<int, AiMemory>
     */
    public function appendMemories(AiThread $thread, array $memories): EloquentCollection
    {
        $existing = $this->existingMemoryIndex($thread);
        $created = new EloquentCollection;

        foreach ($memories as $memory) {
            $content = $this->stringValue($memory['content'] ?? null);

            if ($content === null) {
                continue;
            }

            $normalized = $this->normalizeContent($content);

            if ($normalized === null || isset($existing[$normalized])) {
                continue;
            }

            $payload = [
                'user_id' => $thread->user_id,
                'assistant_key' => $thread->assistant_key,
                'thread_id' => $thread->getKey(),
                'group_id' => $thread->group_id,
                'content' => $content,
                'importance' => $this->resolveImportance($memory),
                'created_at' => Carbon::now(),
            ];

            /** @var AiMemory $saved */
            $saved = $this->memoryService->create($payload);
            $created->push($saved);
            $existing[$normalized] = true;
        }

        return $created;
    }

    /**
     * @return array<string, bool>
     */
    private function existingMemoryIndex(AiThread $thread): array
    {
        $memories = $this->memoryService->query()
            ->where('user_id', $thread->user_id)
            ->where('assistant_key', $thread->assistant_key)
            ->get(['content']);

        $index = [];

        foreach ($memories as $memory) {
            $hash = $this->normalizeContent($memory->content ?? null);

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
     * @param  array<int, string>  $contents
     */
    public function removeMemories(AiThread $thread, array $contents): int
    {
        $normalized = [];

        foreach ($contents as $content) {
            $value = $this->stringValue($content);

            if ($value !== null) {
                $normalized[] = $value;
            }
        }

        if ($normalized === []) {
            return 0;
        }

        return (int) $this->memoryService->query()
            ->where('assistant_key', $thread->assistant_key)
            ->where('user_id', $thread->user_id)
            ->whereIn('content', $normalized)
            ->delete();
    }

    /**
     * @param  array<string, mixed>  $memory
     */
    private function resolveImportance(array $memory): int
    {
        $value = $memory['importance'] ?? config('atlas-nexus.memory.default_importance', 3);
        $intValue = (int) $value;

        return max(1, min(5, $intValue));
    }

    private function effectiveImportance(AiMemory $memory): float
    {
        $base = (int) ($memory->importance ?? $this->resolveImportance([]));
        $createdAt = $memory->created_at ?? $memory->updated_at;
        $ageDays = $createdAt === null ? 0 : $createdAt->diffInDays(Carbon::now());
        $decayDays = $this->decayDays();
        $decaySteps = (int) floor($ageDays / $decayDays);

        return max(0, $base - $decaySteps);
    }

    private function decayDays(): int
    {
        $days = (int) config('atlas-nexus.memory.decay_days', 30);

        return $days > 0 ? $days : 30;
    }
}
