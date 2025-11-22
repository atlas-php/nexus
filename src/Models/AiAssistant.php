<?php

declare(strict_types=1);

namespace Atlas\Nexus\Models;

use Atlas\Core\Models\AtlasModel;
use Atlas\Nexus\Database\Factories\AiAssistantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Class AiAssistant
 *
 * Represents an AI assistant definition including defaults, routing, and metadata used by Nexus.
 * PRD Reference: Atlas Nexus Overview — ai_assistants schema.
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string|null $description
 * @property string|null $default_model
 * @property float|null $temperature
 * @property float|null $top_p
 * @property int|null $max_output_tokens
 * @property int|null $current_prompt_id
 * @property bool $is_active
 * @property array<int, string>|null $provider_tools
 * @property array<int, string>|null $tools
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class AiAssistant extends AtlasModel
{
    protected string $configPrefix = 'atlas-nexus';

    protected string $tableKey = 'ai_assistants';

    /** @use HasFactory<AiAssistantFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'temperature' => 'float',
        'top_p' => 'float',
        'max_output_tokens' => 'int',
        'current_prompt_id' => 'int',
        'is_active' => 'bool',
        'provider_tools' => 'array',
        'tools' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected function defaultTableName(): string
    {
        return 'ai_assistants';
    }

    /**
     * @return HasMany<AiPrompt, self>
     */
    public function prompts(): HasMany
    {
        /** @var HasMany<AiPrompt, self> $relation */
        $relation = $this->hasMany(AiPrompt::class, 'assistant_id');

        return $relation;
    }

    /**
     * @return HasMany<AiThread, self>
     */
    public function threads(): HasMany
    {
        /** @var HasMany<AiThread, self> $relation */
        $relation = $this->hasMany(AiThread::class, 'assistant_id');

        return $relation;
    }

    /**
     * @return BelongsTo<AiPrompt, self>
     */
    public function currentPrompt(): BelongsTo
    {
        /** @var BelongsTo<AiPrompt, self> $relation */
        $relation = $this->belongsTo(AiPrompt::class, 'current_prompt_id');

        return $relation;
    }

    protected static function newFactory(): AiAssistantFactory
    {
        return AiAssistantFactory::new();
    }
}
