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
    public const PROMPT_VERSION = 1;
    public const PROMPT_LABEL = 'Thread Manager';

    public const SYSTEM_PROMPT = <<<PROMPT
You summarize chat threads.
- Return JSON with "title" (<= 8 words) and "summary" (2-3 concise sentences).
- Focus on user goals, tasks, context, and outcomes.
- Do not include tool call details unless they are the main output.
PROMPT;
}
