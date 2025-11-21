<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Models;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Enums\AiMemoryOwnerType;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiThread;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Class AiMemoryService
 *
 * Handles CRUD operations for shared memory entries, scoped retrieval, and safe deletion within a thread context.
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
        AiAssistant $assistant,
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
            'assistant_id' => $assistant->id,
            'thread_id' => $threadScoped ? $thread->id : null,
            'source_message_id' => $sourceMessageId,
            'source_tool_run_id' => $sourceToolRunId,
            'kind' => $kind,
            'content' => $content,
            'metadata' => $metadata,
        ]);

        return $memory;
    }

    /**
     * Fetch accessible memories, optionally filtered by date range. Thread scope is intentionally ignored.
     *
     * @return Collection<int, AiMemory>
     */
    public function listForThread(
        AiAssistant $assistant,
        AiThread $thread,
        ?Carbon $from = null,
        ?Carbon $to = null
    ): Collection {
        $query = $this->scopedQuery($assistant, $thread)
            ->orderBy('id');

        $this->applyDateBounds($query, $from, $to);

        /** @var Collection<int, AiMemory> $memories */
        $memories = $query->get();

        return $memories;
    }

    /**
     * Delete a memory entry only when it belongs to the current assistant and user context.
     */
    public function removeForThread(AiAssistant $assistant, AiThread $thread, int $memoryId): bool
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
        AiAssistant $assistant,
        AiThread $thread,
        AiMemoryOwnerType $ownerType,
        ?int $ownerId = null
    ): int {
        return match ($ownerType) {
            AiMemoryOwnerType::USER => $thread->user_id,
            AiMemoryOwnerType::ASSISTANT => $assistant->id,
            AiMemoryOwnerType::ORG => $ownerId ?? 0,
        };
    }

    /**
     * @return Builder<AiMemory>
     */
    protected function scopedQuery(AiAssistant $assistant, AiThread $thread): Builder
    {
        return $this->query()
            ->where(function (Builder $builder) use ($assistant, $thread): void {
                $builder->where(function (Builder $userQuery) use ($thread): void {
                    $userQuery->where('owner_type', AiMemoryOwnerType::USER->value)
                        ->where('owner_id', $thread->user_id);
                })->orWhere(function (Builder $assistantQuery) use ($assistant): void {
                    $assistantQuery->where('owner_type', AiMemoryOwnerType::ASSISTANT->value)
                        ->where('owner_id', $assistant->id);
                })->orWhere('owner_type', AiMemoryOwnerType::ORG->value);
            })
            ->where(function (Builder $builder) use ($assistant): void {
                $builder->whereNull('assistant_id')
                    ->orWhere('assistant_id', $assistant->id);
            });
    }

    /**
     * @param  Builder<AiMemory>  $query
     */
    protected function applyDateBounds(Builder $query, ?Carbon $from, ?Carbon $to): void
    {
        if ($from !== null) {
            $query->where('created_at', '>=', $from);
        }

        if ($to !== null) {
            $query->where('created_at', '<=', $to);
        }
    }
}
