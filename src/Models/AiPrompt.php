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
 * PRD Reference: Atlas Nexus Overview — ai_assistant_prompts schema.
 *
 * @property int $id
 * @property int $assistant_id
 * @property int|null $user_id
 * @property int $version
 * @property int|null $original_prompt_id
 * @property string $system_prompt
 * @property bool $is_active
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class AiPrompt extends AtlasModel
{
    protected string $configPrefix = 'atlas-nexus';

    protected string $tableKey = 'ai_assistant_prompts';

    /** @use HasFactory<AiPromptFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'assistant_id' => 'int',
        'user_id' => 'int',
        'version' => 'int',
        'original_prompt_id' => 'int',
        'is_active' => 'bool',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected function defaultTableName(): string
    {
        return 'ai_assistant_prompts';
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

    /**
     * @return BelongsTo<self, self>
     */
    public function originalPrompt(): BelongsTo
    {
        /** @var BelongsTo<self, self> $relation */
        $relation = $this->belongsTo(self::class, 'original_prompt_id');

        return $relation;
    }

    /**
     * @return HasMany<self, self>
     */
    public function versions(): HasMany
    {
        /** @var HasMany<self, self> $relation */
        $relation = $this->hasMany(self::class, 'original_prompt_id');

        $assistantId = (int) $this->assistant_id;

        if ($assistantId > 0) {
            $relation->where('assistant_id', $assistantId);
        }

        return $relation;
    }

    protected static function newFactory(): AiPromptFactory
    {
        return AiPromptFactory::new();
    }

    protected static function booted(): void
    {
        static::created(function (self $prompt): void {
            if ($prompt->original_prompt_id === null) {
                $prompt->forceFill(['original_prompt_id' => $prompt->id])->save();
            }
        });
    }
}
