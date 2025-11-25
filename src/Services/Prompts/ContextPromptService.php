<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Prompts;

use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Support\Assistants\ResolvedAssistant;
use Atlas\Nexus\Support\Prompts\ContextPrompt;

/**
 * Class ContextPromptService
 *
 * Generates the assistant-owned context prompt message using the shared builder.
 */
class ContextPromptService
{
    public function __construct(
        private readonly ContextPrompt $contextPrompt
    ) {}

    public function buildForThread(AiThread $thread, ResolvedAssistant $assistant): ?string
    {
        $template = $assistant->contextPrompt();

        if ($template === null) {
            return null;
        }

        $payload = $this->contextPrompt->compose($thread, $assistant, $template);

        if ($payload === null) {
            return null;
        }

        $definition = $assistant->definition();

        if (! $definition->isContextAvailable($payload->summary(), $payload->memories())) {
            return null;
        }

        return $payload->content();
    }
}
