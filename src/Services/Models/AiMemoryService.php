<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Models;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Enums\AiMemoryOwnerType;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Support\Assistants\ResolvedAssistant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Class AiMemoryService
 *
 * Handles CRUD operations for shared memory entries, scoped retrieval, mutation, and safe deletion within a thread context.
 * PRD Reference: Atlas Nexus Overview — ai_memories schema.
 *
 * @extends ModelService<AiMemory>
 */
class AiMemoryService extends ModelService
{
    protected string $model = AiMemory::class;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): AiMemory
    {
        if (! array_key_exists('group_id', $data)) {
            $threadId = $data['thread_id'] ?? null;

            if (is_int($threadId) || is_string($threadId)) {
                $thread = \Atlas\Nexus\Models\AiThread::query()->find($threadId);

                if ($thread !== null) {
                    $data['group_id'] = $thread->group_id;
                }
            }
        }

        /** @var AiMemory $memory */
        $memory = parent::create($data);

        return $memory;
    }

    /**
     * Save a memory entry while enforcing assistant and user scoping rules.
     *
     * @param  array<string, mixed>|null  $metadata
     */
    public function saveForThread(
        ResolvedAssistant $assistant,
        AiThread $thread,
        string $kind,
        string $content,
        AiMemoryOwnerType $ownerType = AiMemoryOwnerType::USER,
        ?array $metadata = null,
        bool $threadScoped = false,
        ?int $sourceMessageId = null,
        ?int $sourceToolRunId = null,
        ?int $ownerId = null
    ): AiMemory {
        $resolvedOwnerId = $this->resolveOwnerId($assistant, $thread, $ownerType, $ownerId);

        /** @var AiMemory $memory */
        $memory = parent::create([
            'owner_type' => $ownerType->value,
            'owner_id' => $resolvedOwnerId,
            'assistant_key' => $assistant->key(),
            'thread_id' => $threadScoped ? $thread->id : null,
            'group_id' => $thread->group_id,
            'source_message_id' => $sourceMessageId,
            'source_tool_run_id' => $sourceToolRunId,
            'kind' => $kind,
            'content' => $content,
            'metadata' => $metadata,
        ]);

        return $memory;
    }

    /**
     * Fetch accessible memories, optionally filtered by specific identifiers.
     * Thread scope is intentionally ignored and the results are ordered newest to oldest.
     *
     * @param  array<int, int>|null  $memoryIds
     * @return Collection<int, AiMemory>
     */
    public function listForThread(
        ResolvedAssistant $assistant,
        AiThread $thread,
        ?array $memoryIds = null
    ): Collection {
        $query = $this->scopedQuery($assistant, $thread)
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($memoryIds !== null && $memoryIds !== []) {
            $ids = array_values(array_filter(array_map(static fn ($id): int => (int) $id, $memoryIds), static fn (int $id): bool => $id > 0));

            if ($ids !== []) {
                $query->whereIn('id', array_values(array_unique($ids)));
            }
        }

        /** @var Collection<int, AiMemory> $memories */
        $memories = $query->get();

        return $memories;
    }

    /**
     * Update a memory entry within the scoped context.
     */
    public function updateForThread(
        ResolvedAssistant $assistant,
        AiThread $thread,
        int $memoryId,
        ?string $kind = null,
        ?string $content = null
    ): AiMemory {
        if ($kind === null && $content === null) {
            throw new RuntimeException('A memory update requires new content or type.');
        }

        /** @var AiMemory|null $memory */
        $memory = $this->scopedQuery($assistant, $thread)
            ->where('id', $memoryId)
            ->first();

        if ($memory === null) {
            throw new RuntimeException('Memory not found for this assistant or user.');
        }

        if ($kind !== null) {
            $memory->kind = $kind;
        }

        if ($content !== null) {
            $memory->content = $content;
        }

        $memory->save();

        return $memory;
    }

    /**
     * Delete a memory entry only when it belongs to the current assistant and user context.
     */
    public function removeForThread(ResolvedAssistant $assistant, AiThread $thread, int $memoryId): bool
    {
        /** @var AiMemory|null $memory */
        $memory = $this->scopedQuery($assistant, $thread)
            ->where('id', $memoryId)
            ->first();

        if ($memory === null) {
            throw new RuntimeException('Memory not found for this assistant or user.');
        }

        return $this->delete($memory);
    }

    protected function resolveOwnerId(
        ResolvedAssistant $assistant,
        AiThread $thread,
        AiMemoryOwnerType $ownerType,
        ?int $ownerId = null
    ): int {
        return match ($ownerType) {
            AiMemoryOwnerType::USER => $thread->user_id,
            AiMemoryOwnerType::ASSISTANT => (int) sprintf('%u', crc32($assistant->key())),
            AiMemoryOwnerType::ORG => $ownerId ?? 0,
        };
    }

    /**
     * @return Builder<AiMemory>
     */
    protected function scopedQuery(ResolvedAssistant $assistant, AiThread $thread): Builder
    {
        $assistantKey = $assistant->key();

        return $this->query()
            ->where(function (Builder $builder) use ($assistantKey, $thread): void {
                $builder->where(function (Builder $userQuery) use ($thread): void {
                    $userQuery->where('owner_type', AiMemoryOwnerType::USER->value)
                        ->where('owner_id', $thread->user_id);
                })->orWhere(function (Builder $assistantQuery) use ($assistantKey): void {
                    $assistantQuery->where('owner_type', AiMemoryOwnerType::ASSISTANT->value)
                        ->where('assistant_key', $assistantKey);
                })->orWhere('owner_type', AiMemoryOwnerType::ORG->value);
            })
            ->where(function (Builder $builder) use ($assistantKey): void {
                $builder->whereNull('assistant_key')
                    ->orWhere('assistant_key', $assistantKey);
            });
    }
}
