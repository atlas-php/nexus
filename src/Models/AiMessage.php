<?php

declare(strict_types=1);

namespace Atlas\Nexus\Models;

use Atlas\Core\Models\AtlasModel;
use Atlas\Nexus\Database\Factories\AiMessageFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Class AiMessage
 *
 * Captures every message exchanged within a thread, including sequencing, token usage, and provider details.
 * PRD Reference: Atlas Nexus Overview — ai_messages schema.
 *
 * @property int $id
 * @property int $thread_id
 * @property int|null $user_id
 * @property string $role
 * @property string $content
 * @property string $content_type
 * @property int $sequence
 * @property string|null $model
 * @property int|null $tokens_in
 * @property int|null $tokens_out
 * @property string|null $provider_response_id
 * @property array<string, mixed>|null $metadata
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 */
class AiMessage extends AtlasModel
{
    protected string $configPrefix = 'atlas-nexus';

    protected string $tableKey = 'ai_messages';

    /** @use HasFactory<AiMessageFactory> */
    use HasFactory;

    /**
     * @var array<string, string>
     */
    protected $casts = [
        'thread_id' => 'int',
        'user_id' => 'int',
        'sequence' => 'int',
        'tokens_in' => 'int',
        'tokens_out' => 'int',
        'metadata' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    protected function defaultTableName(): string
    {
        return 'ai_messages';
    }

    protected static function newFactory(): AiMessageFactory
    {
        return AiMessageFactory::new();
    }
}
