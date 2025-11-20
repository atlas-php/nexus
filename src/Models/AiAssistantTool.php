<?php

declare(strict_types=1);

namespace Atlas\Nexus\Models;

use Atlas\Core\Models\AtlasModel;
use Atlas\Nexus\Database\Factories\AiAssistantToolFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class AiAssistantTool
 *
 * Maps assistants to the tools they are allowed to invoke, capturing optional tool configuration.
 * PRD Reference: Atlas Nexus Overview — ai_assistant_tool schema.
 *
 * @property int $id
 * @property int $assistant_id
 * @property int $tool_id
 * @property array<string, mixed>|null $config
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class AiAssistantTool extends AtlasModel
{
    protected string $configPrefix = 'atlas-nexus';

    protected string $tableKey = 'ai_assistant_tool';

    /** @use HasFactory<AiAssistantToolFactory> */
    use HasFactory;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'assistant_id' => 'int',
        'tool_id' => 'int',
        'config' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected function defaultTableName(): string
    {
        return 'ai_assistant_tool';
    }

    protected static function newFactory(): AiAssistantToolFactory
    {
        return AiAssistantToolFactory::new();
    }
}
