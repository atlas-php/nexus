<?php

declare(strict_types=1);

namespace Atlas\Nexus\Models;

use Atlas\Core\Models\AtlasModel;
use Atlas\Nexus\Database\Factories\AiThreadFactory;
use Atlas\Nexus\Enums\AiThreadStatus;
use Atlas\Nexus\Enums\AiThreadType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;

/**
 * Class AiThread
 *
 * Represents a single conversation thread for assistants, including tool-originated threads and metadata.
 * PRD Reference: Atlas Nexus Overview — ai_threads schema.
 *
 * @property int $id
 * @property int $assistant_id
 * @property int $user_id
 * @property int|null $group_id
 * @property AiThreadType $type
 * @property int|null $parent_thread_id
 * @property int|null $parent_tool_run_id
 * @property string|null $title
 * @property AiThreadStatus $status
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
        'group_id' => 'int',
        'parent_thread_id' => 'int',
        'parent_tool_run_id' => 'int',
        'type' => AiThreadType::class,
        'status' => AiThreadStatus::class,
        'last_message_at' => 'datetime',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected function defaultTableName(): string
    {
        return 'ai_threads';
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
     * @return BelongsTo<AiThread, self>
     */
    public function parentThread(): BelongsTo
    {
        /** @var BelongsTo<AiThread, self> $relation */
        $relation = $this->belongsTo(AiThread::class, 'parent_thread_id');

        return $relation;
    }

    /**
     * @return HasMany<AiThread, self>
     */
    public function children(): HasMany
    {
        /** @var HasMany<AiThread, self> $relation */
        $relation = $this->hasMany(AiThread::class, 'parent_thread_id');

        return $relation;
    }

    /**
     * @return BelongsTo<AiToolRun, self>
     */
    public function parentToolRun(): BelongsTo
    {
        /** @var BelongsTo<AiToolRun, self> $relation */
        $relation = $this->belongsTo(AiToolRun::class, 'parent_tool_run_id');

        return $relation;
    }

    /**
     * @return HasMany<AiToolRun, self>
     */
    public function toolRuns(): HasMany
    {
        /** @var HasMany<AiToolRun, self> $relation */
        $relation = $this->hasMany(AiToolRun::class, 'thread_id');

        return $relation;
    }

    /**
     * @return HasMany<AiMessage, self>
     */
    public function messages(): HasMany
    {
        /** @var HasMany<AiMessage, self> $relation */
        $relation = $this->hasMany(AiMessage::class, 'thread_id');

        return $relation;
    }

    /**
     * @return HasMany<AiMemory, self>
     */
    public function memories(): HasMany
    {
        /** @var HasMany<AiMemory, self> $relation */
        $relation = $this->hasMany(AiMemory::class, 'thread_id');

        return $relation;
    }

    protected static function newFactory(): AiThreadFactory
    {
        return AiThreadFactory::new();
    }
}
