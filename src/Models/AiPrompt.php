<?php

declare(strict_types=1);

namespace Atlas\Nexus\Models;

use Atlas\Core\Models\AtlasModel;
use Atlas\Nexus\Database\Factories\AiPromptFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;

/**
 * Class AiPrompt
 *
 * Stores versioned system prompts for assistants with optional user scoping.
 * PRD Reference: Atlas Nexus Overview — ai_prompts schema.
 *
 * @property int $id
 * @property int|null $user_id
 * @property int $assistant_id
 * @property int $version
 * @property string|null $label
 * @property string $system_prompt
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class AiPrompt extends AtlasModel
{
    protected string $configPrefix = 'atlas-nexus';

    protected string $tableKey = 'ai_prompts';

    /** @use HasFactory<AiPromptFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'user_id' => 'int',
        'assistant_id' => 'int',
        'version' => 'int',
        'is_active' => 'bool',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected function defaultTableName(): string
    {
        return 'ai_prompts';
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
     * @return BelongsTo<AuthenticatableUser, self>
     */
    public function user(): BelongsTo
    {
        $providerModel = config('auth.providers.users.model');
        $fallback = config('auth.model', AuthenticatableUser::class);

        /** @var class-string<AuthenticatableUser> $modelClass */
        $modelClass = $providerModel ?? $fallback;

        /** @var BelongsTo<AuthenticatableUser, self> $relation */
        $relation = $this->belongsTo($modelClass, 'user_id');

        return $relation;
    }

    /**
     * @return HasMany<AiThread, self>
     */
    public function threads(): HasMany
    {
        /** @var HasMany<AiThread, self> $relation */
        $relation = $this->hasMany(AiThread::class, 'prompt_id');

        return $relation;
    }

    protected static function newFactory(): AiPromptFactory
    {
        return AiPromptFactory::new();
    }
}
