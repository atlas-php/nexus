<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Models;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Models\AiPrompt;
use Illuminate\Database\Eloquent\Builder;

/**
 * Class AiPromptService
 *
 * Wraps CRUD operations for prompts so assistant versions can be managed consistently.
 * PRD Reference: Atlas Nexus Overview — ai_prompts schema.
 *
 * @extends ModelService<AiPrompt>
 */
class AiPromptService extends ModelService
{
    protected string $model = AiPrompt::class;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): AiPrompt
    {
        $data['version'] = $data['version'] ?? 1;

        /** @var AiPrompt $prompt */
        $prompt = parent::create($data);

        return $prompt;
    }

    /**
     * Update a prompt inline or spawn a new version with the provided changes.
     *
     * @param  array<string, mixed>  $data
     */
    public function edit(AiPrompt $prompt, array $data, bool $createNewVersion = false): AiPrompt
    {
        if (! $createNewVersion) {
            /** @var AiPrompt $updated */
            $updated = $this->update($prompt, $data);

            return $updated;
        }

        return $this->createVersionFrom($prompt, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createVersionFrom(AiPrompt $prompt, array $data): AiPrompt
    {
        $originalId = $prompt->original_prompt_id ?? $prompt->id;

        if ($prompt->original_prompt_id === null) {
            $prompt->forceFill(['original_prompt_id' => $prompt->id])->save();
        }

        $latestVersion = $this->lineageQuery($originalId)->max('version') ?? $prompt->version;

        $payload = array_merge([
            'version' => ((int) $latestVersion) + 1,
            'original_prompt_id' => $originalId,
            'user_id' => $prompt->user_id,
            'is_active' => $prompt->is_active,
            'label' => $prompt->label,
            'system_prompt' => $prompt->system_prompt,
        ], $data);

        /** @var AiPrompt $newPrompt */
        $newPrompt = $this->create($payload);

        return $newPrompt;
    }

    /**
     * @return Builder<AiPrompt>
     */
    protected function lineageQuery(int $originalId): Builder
    {
        return $this->query()
            ->where(function (Builder $builder) use ($originalId): void {
                $builder->where('id', $originalId)
                    ->orWhere('original_prompt_id', $originalId);
            });
    }
}
