<?php

declare(strict_types=1);

namespace Atlas\Nexus\Support\Web;

/**
 * Class WebSummaryDefaults
 *
 * Holds the built-in assistant and prompt definitions used when summarizing fetched website content.
 */
class WebSummaryDefaults
{
    public const ASSISTANT_SLUG = 'web-summarizer';

    public const ASSISTANT_NAME = 'Web Summarizer';

    public const ASSISTANT_DESCRIPTION = 'Summarizes fetched website content for quick context.';

    public const SYSTEM_PROMPT = <<<'PROMPT'
You are a concise web content summarizer.
- Return 4-6 bullet points that capture the most important facts, claims, numbers, and entities.
- Ignore navigation, ads, and boilerplate text. Do not invent information that is not present.
- Reference the source URL in parentheses when helpful.
- Use plain language; keep each bullet short.
PROMPT;
}
