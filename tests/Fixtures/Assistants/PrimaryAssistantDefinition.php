<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Fixtures\Assistants;

use Atlas\Nexus\Models\AiThread;

class PrimaryAssistantDefinition extends ConfigurableAssistantDefinition
{
    /**
     * @return array<string, mixed>
     */
    protected static function defaults(): array
    {
        return [
            'key' => 'general-assistant',
            'name' => 'General Assistant',
            'description' => 'Test general-purpose assistant.',
            'system_prompt' => 'You are a helpful test assistant.',
            'default_model' => 'gpt-test',
            'temperature' => 0.2,
            'top_p' => 0.5,
            'max_output_tokens' => 512,
            'max_default_steps' => 8,
            'tools' => ['fetch_more_context'],
            'provider_tools' => [],
            'metadata' => [],
            'reasoning' => null,
            'context_prompt' => <<<'PROMPT'
Recent known context for this user.

{CONTEXT_PROMPT.LAST_SUMMARY_SECTION}

{CONTEXT_PROMPT.MEMORIES_SECTION}
PROMPT,
        ];
    }

    public function isContextAvailable(AiThread $thread): bool
    {
        $configured = $this->data('context_available');

        return is_bool($configured) ? $configured : false;
    }
}
