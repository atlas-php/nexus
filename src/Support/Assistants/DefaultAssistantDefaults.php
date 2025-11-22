<?php

declare(strict_types=1);

namespace Atlas\Nexus\Support\Assistants;

/**
 * Class DefaultAssistantDefaults
 *
 * Defines the baseline assistant and prompt that ship with Nexus for general use.
 */
class DefaultAssistantDefaults
{
    public const ASSISTANT_SLUG = 'assistant';

    public const ASSISTANT_NAME = 'Assistant';

    public const ASSISTANT_DESCRIPTION = 'General-purpose AI assistant for conversation and task help.';

    public const PROMPT_VERSION = 1;

    public const PROMPT_LABEL = 'Assistant';

    public const SYSTEM_PROMPT = <<<'PROMPT'
You are a helpful AI assistant.
- Answer clearly and concisely.
- Ask for clarification when context is missing.
- When unsure, state assumptions before providing an answer.
PROMPT;
}
