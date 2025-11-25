<?php

declare(strict_types=1);

namespace Atlas\Nexus\Assistants;

use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiThread;
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

    /**
     * @return array<string, string>
     */
    public function reasoning(): ?array
    {
        return [
            'effort' => 'low',
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

    public function contextPrompt(): ?string
    {
        return <<<'PROMPT'
Recent known context for this user.

{CONTEXT_PROMPT.LAST_SUMMARY_SECTION}

{CONTEXT_PROMPT.MEMORIES_SECTION}
PROMPT;
    }

    public function isContextAvailable(AiThread $thread): bool
    {
        if ($this->hasSummary($thread)) {
            return true;
        }

        $userId = $thread->getAttribute('user_id');

        if (! is_int($userId)) {
            return false;
        }

        return AiMemory::query()
            ->where('assistant_key', $thread->assistant_key)
            ->where('user_id', $userId)
            ->exists();
    }

    private function hasSummary(AiThread $thread): bool
    {
        $summary = $thread->summary;

        if (is_string($summary) && trim($summary) !== '') {
            return true;
        }

        $userId = $thread->getAttribute('user_id');

        if (! is_int($userId)) {
            return false;
        }

        return AiThread::query()
            ->where('assistant_key', $thread->assistant_key)
            ->where('user_id', $userId)
            ->whereNotNull('summary')
            ->exists();
    }
}
