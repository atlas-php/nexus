<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Models\AiPrompt;

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
        /** @var AiPrompt $prompt */
        $prompt = parent::create($data);

        return $prompt;
    }
}
