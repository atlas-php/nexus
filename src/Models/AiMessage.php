<?php

declare(strict_types=1);

namespace Atlas\Nexus\Models;

use Atlas\Core\Models\AtlasModel;
use Atlas\Nexus\Database\Factories\AiMessageFactory;
use Atlas\Nexus\Enums\AiMessageContentType;
use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as AuthenticatableUser;

/**
 * Class AiMessage
 *
 * Captures every message exchanged within a thread, including sequencing, token usage, and provider details.
 * PRD Reference: Atlas Nexus Overview — ai_messages schema.
 *
 * @property int $id
 * @property int $thread_id
 * @property int|null $user_id
 * @property int|null $group_id
 * @property AiMessageRole $role
 * @property string $content
 * @property AiMessageContentType $content_type
 * @property int $sequence
 * @property AiMessageStatus $status
 * @property string|null $failed_reason
 * @property string|null $model
 * @property int|null $tokens_in
 * @property int|null $tokens_out
 * @property string|null $provider_response_id
 * @property array<string, mixed>|null $raw_response
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property \Illuminate\Support\Carbon|null $deleted_at
 */
class AiMessage extends AtlasModel
{
    protected string $configPrefix = 'atlas-nexus';

    protected string $tableKey = 'ai_messages';

    /** @use HasFactory<AiMessageFactory> */
    use HasFactory;

    use SoftDeletes;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'thread_id' => 'int',
        'user_id' => 'int',
        'group_id' => 'int',
        'sequence' => 'int',
        'status' => AiMessageStatus::class,
        'tokens_in' => 'int',
        'tokens_out' => 'int',
        'role' => AiMessageRole::class,
        'content_type' => AiMessageContentType::class,
        'raw_response' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    protected function defaultTableName(): string
    {
        return 'ai_messages';
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
     * @return HasMany<AiToolRun, self>
     */
    public function toolRuns(): HasMany
    {
        /** @var HasMany<AiToolRun, self> $relation */
        $relation = $this->hasMany(AiToolRun::class, 'assistant_message_id');

        return $relation;
    }

    protected static function newFactory(): AiMessageFactory
    {
        return AiMessageFactory::new();
    }
}
