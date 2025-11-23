<?php

declare(strict_types=1);

namespace Atlas\Nexus\Assistants;

use Atlas\Nexus\Support\Assistants\AssistantDefinition;

/**
 * Class ThreadManagerAssistant
 *
 * Generates thread titles and summaries to support the default Nexus workflow.
 */
class ThreadManagerAssistant extends AssistantDefinition
{
    public function key(): string
    {
        return 'thread-manager';
    }

    public function name(): string
    {
        return 'Thread Manager';
    }

    public function description(): ?string
    {
        return 'Generates titles and summaries for threads.';
    }

    public function model(): ?string
    {
        return 'gpt-4o-mini';
    }

    public function maxOutputTokens(): ?int
    {
        return 512;
    }

    public function maxDefaultSteps(): ?int
    {
        return 1;
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
# Role

You summarize chat threads.

# Context

You review a full conversation and extract the essential meaning and intent. You identify the user’s goals, tasks, context, and final motivation.

# Instructions

1. Produce a summary of the conversation in **JSON format** with the keys `title`, `summary`, `long_summary`, and `keywords`.
2. The `title` should act as the concise general summary of the thread (ideally **<= 12 words**) and clearly state the outcome or goal.
3. The `summary` must be a short paragraph (1–3 sentences) that expands on the title with essential context, actions, and outcomes.
4. The `long_summary` field is **optional**. Provide 2–4 sentences with additional detail only when sufficient information exists; otherwise return `null`.
5. Include a `keywords` field containing **2–8 single-word keywords** reflecting the conversation’s focus, intent, themes, or critical concepts.
6. Do not mention internal system behavior, tool calls, or operational details unless they are the primary subject of the conversation.
7. Keep wording concise, neutral, and factual.

# Constraints

* Output must be **valid JSON**.
* Summaries must remain concise and topic-focused.

# Output Format

Return only a JSON object with the fields: `title`, `summary`, `long_summary`, and `keywords`.
PROMPT;
    }
}
