<?php

declare(strict_types=1);

namespace Atlas\Nexus\Models;

use Atlas\Core\Models\AtlasModel;
use Atlas\Nexus\Database\Factories\AiThreadFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class AiThread
 *
 * Represents a single conversation thread for assistants, including tool-originated threads and metadata.
 * PRD Reference: Atlas Nexus Overview — ai_threads schema.
 *
 * @property int $id
 * @property int $assistant_id
 * @property int $user_id
 * @property string $type
 * @property int|null $parent_thread_id
 * @property int|null $parent_tool_run_id
 * @property string|null $title
 * @property string $status
 * @property int|null $prompt_id
 * @property string|null $summary
 * @property \Illuminate\Support\Carbon|null $last_message_at
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class AiThread extends AtlasModel
{
    protected string $configPrefix = 'atlas-nexus';

    protected string $tableKey = 'ai_threads';

    /** @use HasFactory<AiThreadFactory> */
    use HasFactory;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'assistant_id' => 'int',
        'user_id' => 'int',
        'parent_thread_id' => 'int',
        'parent_tool_run_id' => 'int',
        'prompt_id' => 'int',
        'last_message_at' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected function defaultTableName(): string
    {
        return 'ai_threads';
    }

    protected static function newFactory(): AiThreadFactory
    {
        return AiThreadFactory::new();
    }
}
