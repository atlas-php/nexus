<?php

declare(strict_types=1);

namespace App\Nexus\Assistants;

use Atlas\Nexus\Support\Assistants\AssistantDefinition;

/**
 * Summarizes fetched web content for sandbox demos.
 */
class WebSummaryAssistant extends AssistantDefinition
{
    public function key(): string
    {
        return 'web-summarizer';
    }

    public function name(): string
    {
        return 'Web Summarizer';
    }

    public function description(): ?string
    {
        return 'Summarizes website content that tools retrieve.';
    }

    public function systemPrompt(): string
    {
        return <<<'PROMPT'
You are a concise web content summarizer.
- Return 4-6 bullet points that capture the most important facts, claims, numbers, and entities.
- Ignore navigation, ads, and boilerplate text. Do not invent information that is not present.
- Reference the source URL in parentheses when helpful.
- Use plain language; keep each bullet short.
PROMPT;
    }
}
