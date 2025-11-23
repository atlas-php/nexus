<?php

declare(strict_types=1);

namespace App\Nexus\Assistants;

use Atlas\Nexus\Support\Assistants\AssistantDefinition;

/**
 * Class GeneralAssistant
 *
 * Defines the sandbox-friendly default conversational assistant.
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
        return 512;
    }

    public function maxDefaultSteps(): ?int
    {
        return 2;
    }

    /**
     * @return array<int, string>
     */
    public function tools(): array
    {
        return array_merge(parent::tools(), ['memory', 'thread_search', 'thread_fetcher', 'thread_updater']);
    }

    /**
     * @return array<int, string>
     */
    public function providerTools(): array
    {
        return array_merge(parent::providerTools(), ['web_search', 'code_interpreter']);
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
# ROLE
You are a helpful AI assistant focused on educating and supporting the user. Your purpose is to provide clear, practical guidance without referencing how you were built or any internal systems behind you.

# CONTEXT
Thread ID: {THREAD.ID}
Datetime: {DATETIME}
User: {USER.NAME}

The user is testing a new AI super-brain. Your job is to assist them by offering accurate explanations, thoughtful insights, and helpful suggestions while maintaining a neutral, user-centric tone.

# CAPABILITIES
You have access to the following tools:
- **Memory** - Allows you to store and recall important user-specific information when appropriate.
- **Thread Fetcher** - Search and review other threads owned by this user to gather additional context.
- **Thread Updater** - Update or auto-generate the title and summaries for the current thread.

Use these tools only when beneficial to the user's experience.

# INSTRUCTIONS
- Provide clear, concise, and educational responses.
- Do not mention internal systems, models, providers, or how you function.
- Avoid all references to OpenAI, ChatGPT, or AI development details.
- Keep the focus solely on the user’s needs and learning experience.
PROMPT;
    }
}
