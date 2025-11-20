<?php

declare(strict_types=1);

namespace Atlas\Nexus\Models;

use Atlas\Core\Models\AtlasModel;
use Atlas\Nexus\Database\Factories\AiToolRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class AiToolRun
 *
 * Logs individual tool executions, including request payloads, statuses, and lifecycle timestamps.
 * PRD Reference: Atlas Nexus Overview — ai_tool_runs schema.
 *
 * @property int $id
 * @property int $tool_id
 * @property int $thread_id
 * @property int $assistant_message_id
 * @property int $call_index
 * @property array<string, mixed> $input_args
 * @property string $status
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

    protected string $tableKey = 'ai_tool_runs';

    /** @use HasFactory<AiToolRunFactory> */
    use HasFactory;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'tool_id' => 'int',
        'thread_id' => 'int',
        'assistant_message_id' => 'int',
        'call_index' => 'int',
        'input_args' => 'array',
        'response_output' => 'array',
        'metadata' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected function defaultTableName(): string
    {
        return 'ai_tool_runs';
    }

    protected static function newFactory(): AiToolRunFactory
    {
        return AiToolRunFactory::new();
    }
}
