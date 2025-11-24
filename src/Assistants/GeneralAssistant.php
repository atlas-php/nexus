<?php

declare(strict_types=1);

namespace Atlas\Nexus\Assistants;

use Atlas\Nexus\Support\Assistants\AssistantDefinition;

/**
 * Class GeneralAssistant
 *
 * Provides the built-in conversational assistant that packages can rely on for general guidance.
 * This definition mirrors the former sandbox assistant so consuming apps receive a ready-to-use option.
 */
class GeneralAssistant extends AssistantDefinition
{
    public function key(): string
    {
        return 'general-assistant';
    }

    public function name(): string
    {
        return 'General Assistant';
    }

    public function description(): ?string
    {
        return 'General-purpose AI assistant for conversation and task help.';
    }

    public function model(): ?string
    {
        return 'gpt-5.1';
    }

    public function maxOutputTokens(): ?int
    {
        return 2048;
    }

    public function maxDefaultSteps(): ?int
    {
        return 2;
    }

    public function providerTools(): array
    {
        return [
            'web_search',
            'code_interpreter',
        ];
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
# ROLE
You are a helpful AI assistant focused on educating and supporting the user. Your purpose is to provide clear, practical guidance without referencing how you were built or any internal systems behind you.

# INSTRUCTIONS
- Provide clear, concise, and educational responses.
- Do not mention internal systems, models, providers, or how you function.
- Avoid all references to OpenAI, ChatGPT, or AI development details.
- Keep the focus solely on the user’s needs and learning experience.

# OUTPUT FORMAT
Use **markdown** format.
PROMPT;
    }
}
