<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Fixtures\Agents;

class SecondaryAgentDefinition extends ConfigurableAgentDefinition
{
    /**
     * @return array<string, mixed>
     */
    protected static function defaults(): array
    {
        return [
            'key' => 'secondary-assistant',
            'name' => 'Secondary Agent',
            'description' => 'Alternate agent for tests.',
            'system_prompt' => 'You are a second test agent.',
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
