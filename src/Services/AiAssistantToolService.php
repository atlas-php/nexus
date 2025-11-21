<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Models\AiAssistantTool;

/**
 * Class AiAssistantToolService
 *
 * Manages pivot records connecting assistants to tools with optional configuration payloads.
 * PRD Reference: Atlas Nexus Overview — ai_assistant_tool schema.
 *
 * @extends ModelService<AiAssistantTool>
 */
class AiAssistantToolService extends ModelService
{
    protected string $model = AiAssistantTool::class;
}
