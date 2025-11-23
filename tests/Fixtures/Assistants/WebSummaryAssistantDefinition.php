<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Fixtures\Assistants;

class WebSummaryAssistantDefinition extends ConfigurableAssistantDefinition
{
    /**
     * @return array<string, mixed>
     */
    protected static function defaults(): array
    {
        return [
            'key' => 'web-summarizer',
            'name' => 'Web Summarizer',
            'description' => 'Summarizes fetched website content.',
            'system_prompt' => <<<'PROMPT'
You are a concise web content summarizer.
- Return 4-6 bullet points that capture the most important facts, claims, numbers, and entities.
- Ignore navigation, ads, and boilerplate text. Do not invent information that is not present.
- Reference the source URL in parentheses when helpful.
- Use plain language; keep each bullet short.
PROMPT,
            'default_model' => 'gpt-web-summary',
            'tools' => [],
            'provider_tools' => [],
            'metadata' => [],
        ];
    }
}
