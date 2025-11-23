<?php

declare(strict_types=1);

namespace Atlas\Nexus\Models;

use Atlas\Core\Models\AtlasModel;
use Atlas\Nexus\Database\Factories\AiToolRunFactory;
use Atlas\Nexus\Enums\AiToolRunStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class AiToolRun
 *
 * Logs individual tool executions, including request payloads, statuses, and lifecycle timestamps.
 * PRD Reference: Atlas Nexus Overview — ai_message_tools schema.
 *
 * @property int $id
 * @property int|null $group_id
 * @property string $tool_key
 * @property int $thread_id
 * @property string $assistant_key
 * @property int $assistant_message_id
 * @property int $call_index
 * @property array<string, mixed> $input_args
 * @property AiToolRunStatus $status
 * @property array<string, mixed>|null $response_output
 * @property array<string, mixed>|null $metadata
 * @property string|null $error_message
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class AiToolRun extends AtlasModel
{
    protected string $configPrefix = 'atlas-nexus';

    protected string $tableKey = 'ai_message_tools';

    /** @use HasFactory<AiToolRunFactory> */
    use HasFactory;
    use SoftDeletes;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'group_id' => 'int',
        'tool_key' => 'string',
        'thread_id' => 'int',
        'assistant_message_id' => 'int',
        'assistant_key' => 'string',
        'call_index' => 'int',
        'input_args' => 'array',
        'status' => AiToolRunStatus::class,
        'response_output' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected function defaultTableName(): string
    {
        return 'ai_message_tools';
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
    public function assistantMessage(): BelongsTo
    {
        /** @var BelongsTo<AiMessage, self> $relation */
        $relation = $this->belongsTo(AiMessage::class, 'assistant_message_id');

        return $relation;
    }

    /**
     * @return HasMany<AiThread, self>
     */
    public function toolThreads(): HasMany
    {
        /** @var HasMany<AiThread, self> $relation */
        $relation = $this->hasMany(AiThread::class, 'parent_tool_run_id');

        return $relation;
    }

    protected static function newFactory(): AiToolRunFactory
    {
        return AiToolRunFactory::new();
    }
}
