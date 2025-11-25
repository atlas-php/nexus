<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Models;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Models\AiMemory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Class AiMemoryService
 *
 * Coordinates CRUD operations for assistant memory records and normalizes persisted attributes.
 *
 * @extends ModelService<AiMemory>
 */
class AiMemoryService extends ModelService
{
    protected string $model = AiMemory::class;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): Model
    {
        return parent::create($this->normalizePayload($data));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    public function update(Model $model, array $data): Model
    {
        return parent::update($model, $this->normalizePayload($data));
    }

    /**
     * Ensure text and array fields stay within required limits before persistence.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function normalizePayload(array $data): array
    {
        if (array_key_exists('content', $data) && is_string($data['content'])) {
            $trimmed = trim($data['content']);
            $data['content'] = $trimmed === '' ? null : Str::limit($trimmed, 255, '');
        }

        return $data;
    }
}
