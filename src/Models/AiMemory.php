<?php

declare(strict_types=1);

namespace Atlas\Nexus\Models;

use Atlas\Core\Models\AtlasModel;
use Atlas\Nexus\Database\Factories\AiMemoryFactory;
use Atlas\Nexus\Enums\AiMemoryOwnerType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class AiMemory
 *
 * Stores shared memory fragments tied to owners, assistants, and provenance for retrieval across threads.
 * PRD Reference: Atlas Nexus Overview — ai_memories schema.
 *
 * @property int $id
 * @property AiMemoryOwnerType $owner_type
 * @property int $owner_id
 * @property int|null $group_id
 * @property int|null $assistant_id
 * @property int|null $thread_id
 * @property int|null $source_message_id
 * @property int|null $source_tool_run_id
 * @property string $kind
 * @property string $content
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class AiMemory extends AtlasModel
{
    protected string $configPrefix = 'atlas-nexus';

    protected string $tableKey = 'ai_memories';

    /** @use HasFactory<AiMemoryFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'owner_id' => 'int',
        'group_id' => 'int',
        'assistant_id' => 'int',
        'thread_id' => 'int',
        'source_message_id' => 'int',
        'source_tool_run_id' => 'int',
        'owner_type' => AiMemoryOwnerType::class,
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected function defaultTableName(): string
    {
        return 'ai_memories';
    }

    /**
     * @return BelongsTo<AiAssistant, self>
     */
    public function assistant(): BelongsTo
    {
        /** @var BelongsTo<AiAssistant, self> $relation */
        $relation = $this->belongsTo(AiAssistant::class, 'assistant_id');

        return $relation;
    }

    /**
     * @return BelongsTo<AiThread, self>
     */
    public function thread(): BelongsTo
    {
        /** @var BelongsTo<AiThread, self> $relation */
        $relation = $this->belongsTo(AiThread::class, 'thread_id');

        return $relation;
    }

    /**
     * @return BelongsTo<AiMessage, self>
     */
    public function sourceMessage(): BelongsTo
    {
        /** @var BelongsTo<AiMessage, self> $relation */
        $relation = $this->belongsTo(AiMessage::class, 'source_message_id');

        return $relation;
    }

    /**
     * @return BelongsTo<AiToolRun, self>
     */
    public function sourceToolRun(): BelongsTo
    {
        /** @var BelongsTo<AiToolRun, self> $relation */
        $relation = $this->belongsTo(AiToolRun::class, 'source_tool_run_id');

        return $relation;
    }

    protected static function newFactory(): AiMemoryFactory
    {
        return AiMemoryFactory::new();
    }
}
