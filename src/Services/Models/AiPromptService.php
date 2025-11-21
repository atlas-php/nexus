<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Models;

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
}
