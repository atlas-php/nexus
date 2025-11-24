<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Fixtures\Assistants;

class SecondaryAssistantDefinition extends ConfigurableAssistantDefinition
{
    /**
     * @return array<string, mixed>
     */
    protected static function defaults(): array
    {
        return [
            'key' => 'secondary-assistant',
            'name' => 'Secondary Assistant',
            'description' => 'Alternate assistant for tests.',
            'system_prompt' => 'You are a second test assistant.',
            'default_model' => 'gpt-test',
            'temperature' => 0.1,
            'top_p' => 0.3,
            'max_output_tokens' => 256,
            'tools' => ['fetch_more_context'],
            'provider_tools' => [],
            'metadata' => [],
        ];
    }
}
