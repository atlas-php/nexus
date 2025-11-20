<?php

declare(strict_types=1);

namespace Atlas\Nexus\Models;

use Atlas\Core\Models\AtlasModel;
use Atlas\Nexus\Database\Factories\AiMemoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class AiMemory
 *
 * Stores shared memory fragments tied to owners, assistants, and provenance for retrieval across threads.
 * PRD Reference: Atlas Nexus Overview — ai_memories schema.
 *
 * @property int $id
 * @property string $owner_type
 * @property int $owner_id
 * @property int|null $assistant_id
 * @property int|null $thread_id
 * @property int|null $source_message_id
 * @property int|null $source_tool_run_id
 * @property string $kind
 * @property string $content
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class AiMemory extends AtlasModel
{
    protected string $configPrefix = 'atlas-nexus';

    protected string $tableKey = 'ai_memories';

    /** @use HasFactory<AiMemoryFactory> */
    use HasFactory;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'owner_id' => 'int',
        'assistant_id' => 'int',
        'thread_id' => 'int',
        'source_message_id' => 'int',
        'source_tool_run_id' => 'int',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected function defaultTableName(): string
    {
        return 'ai_memories';
    }

    protected static function newFactory(): AiMemoryFactory
    {
        return AiMemoryFactory::new();
    }
}
