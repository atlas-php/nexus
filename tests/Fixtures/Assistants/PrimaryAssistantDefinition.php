<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Fixtures\Assistants;

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
            'tools' => ['memory', 'thread_search'],
            'provider_tools' => [],
            'metadata' => [],
        ];
    }
}
