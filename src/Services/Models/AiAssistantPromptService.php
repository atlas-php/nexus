<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Models;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Models\AiAssistantPrompt;

/**
 * Class AiAssistantPromptService
 *
 * Wraps CRUD operations for prompts so assistant versions can be managed consistently.
 * PRD Reference: Atlas Nexus Overview — ai_assistant_prompts schema.
 *
 * @extends ModelService<AiAssistantPrompt>
 */
class AiAssistantPromptService extends ModelService
{
    protected string $model = AiAssistantPrompt::class;

    /**
     * @param  array<string, mixed>  $data
     */
    public function create(array $data): AiAssistantPrompt
    {
        $assistantId = $this->extractAssistantId($data);
        $data['assistant_id'] = $assistantId;

        $data['version'] = $data['version'] ?? $this->nextVersionForAssistant($assistantId);

        /** @var AiAssistantPrompt $prompt */
        $prompt = parent::create($data);

        return $prompt;
    }

    /**
     * Spawn a new prompt version from an existing prompt with the provided changes.
     *
     * @param  array<string, mixed>  $data
     */
    public function edit(AiAssistantPrompt $prompt, array $data): AiAssistantPrompt
    {
        return $this->createVersionFrom($prompt, $data);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function createVersionFrom(AiAssistantPrompt $prompt, array $data): AiAssistantPrompt
    {
        $assistantId = (int) $prompt->assistant_id;

        if ($assistantId <= 0) {
            throw new \RuntimeException('Prompt is missing an assistant_id.');
        }
        $originalId = $prompt->original_prompt_id ?? $prompt->id;

        if ($prompt->original_prompt_id === null) {
            $prompt->forceFill(['original_prompt_id' => $prompt->id])->save();
        }

        $payload = array_merge([
            'assistant_id' => $assistantId,
            'version' => $this->nextVersionForAssistant($assistantId),
            'original_prompt_id' => $originalId,
            'user_id' => $prompt->user_id,
            'is_active' => $prompt->is_active,
            'system_prompt' => $prompt->system_prompt,
        ], $data);

        /** @var AiAssistantPrompt $newPrompt */
        $newPrompt = parent::create($payload);

        return $newPrompt;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    protected function extractAssistantId(array $data): int
    {
        $assistantId = $data['assistant_id'] ?? null;

        if (! is_numeric($assistantId) || (int) $assistantId <= 0) {
            throw new \InvalidArgumentException('Assistant ID is required when creating prompts.');
        }

        return (int) $assistantId;
    }

    protected function nextVersionForAssistant(int $assistantId): int
    {
        $latest = $this->query()
            ->where('assistant_id', $assistantId)
            ->max('version');

        return ((int) $latest) + 1;
    }
}
