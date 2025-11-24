<?php

declare(strict_types=1);

namespace Atlas\Nexus\Models;

use Atlas\Core\Models\AtlasModel;
use Atlas\Nexus\Database\Factories\AiMemoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class AiMemory
 *
 * Represents a single assistant memory entry linked to a user, assistant, and optional thread context.
 * Each record is stored individually to preserve provenance and allow soft deletion.
 *
 * @property int $id
 * @property int|null $group_id
 * @property int $user_id
 * @property string $assistant_id
 * @property int|null $thread_id
 * @property string $content
 * @property array<int, int>|null $source_message_ids
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
        'group_id' => 'int',
        'user_id' => 'int',
        'assistant_id' => 'string',
        'thread_id' => 'int',
        'content' => 'string',
        'source_message_ids' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected function defaultTableName(): string
    {
        return 'ai_memories';
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

    protected static function newFactory(): AiMemoryFactory
    {
        return AiMemoryFactory::new();
    }
}
