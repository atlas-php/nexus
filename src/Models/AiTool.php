<?php

declare(strict_types=1);

namespace Atlas\Nexus\Models;

use Atlas\Core\Models\AtlasModel;
use Atlas\Nexus\Database\Factories\AiToolFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class AiTool
 *
 * Defines a registered tool with schema, handler binding, and activation state for assistant usage.
 * PRD Reference: Atlas Nexus Overview — ai_tools schema.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property array<string, mixed> $schema
 * @property string $handler_class
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class AiTool extends AtlasModel
{
    protected string $configPrefix = 'atlas-nexus';

    protected string $tableKey = 'ai_tools';

    /** @use HasFactory<AiToolFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'schema' => 'array',
        'is_active' => 'bool',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected function defaultTableName(): string
    {
        return 'ai_tools';
    }

    protected static function newFactory(): AiToolFactory
    {
        return AiToolFactory::new();
    }
}
