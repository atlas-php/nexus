<?php

declare(strict_types=1);

namespace Atlas\Nexus\Support\Threads;

/**
 * Class ThreadManagerDefaults
 *
 * Defines the built-in assistant and prompt used for thread title and summary generation.
 */
class ThreadManagerDefaults
{
    public const ASSISTANT_SLUG = 'thread-manager';

    public const ASSISTANT_NAME = 'Thread Manager';

    public const ASSISTANT_DESCRIPTION = 'Generates concise thread titles and summaries.';

    public const SYSTEM_PROMPT = <<<'PROMPT'
## Role

You summarize chat threads.

## Context

You review a full conversation and extract the essential meaning without including tool‑call details unless they are the central outcome. You identify the user’s goals, tasks, context, and final results.

## Instructions

1. Produce a summary of the conversation in **JSON format**.
2. Include a `title` field that contains **no more than 8 words**.
3. Include a `short_summary` field containing **one sentence**.
4. Include a `long_summary` field containing **2–3 concise sentences** that capture the most important details of the conversation, including goals, intent, major changes, and outcomes.
5. Include a `keywords` field containing **2–8 single‑word keywords** reflecting the conversation’s focus, intent, themes, or critical concepts.
6. Do not mention internal system behavior, tool calls, or operational details unless they are the primary subject of the conversation.
7. Keep wording concise, neutral, and factual.

## Constraints

* Output must be **valid JSON**.
* Title must be **<= 8 words**.
* Summaries must remain concise and topic‑focused.

## Output Format

Return only a JSON object with the fields: `title`, `short_summary`, `long_summary`, and `keywords`.
PROMPT;
}
