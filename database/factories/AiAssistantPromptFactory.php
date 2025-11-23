<?php

declare(strict_types=1);

namespace Atlas\Nexus\Database\Factories;

use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiAssistantPrompt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Class AiAssistantPromptFactory
 *
 * Produces versioned prompt records for assistants with optional user ownership.
 * PRD Reference: Atlas Nexus Overview — ai_assistant_prompts schema.
 *
 * @extends Factory<AiAssistantPrompt>
 */
class AiAssistantPromptFactory extends Factory
{
    protected $model = AiAssistantPrompt::class;

    public function definition(): array
    {
        return [
            'assistant_id' => AiAssistant::factory(),
            'assistant_prompt_id' => null,
            'user_id' => $this->faker->optional()->numberBetween(1, 5_000),
            'version' => 1,
            'original_prompt_id' => null,
            'system_prompt' => $this->faker->paragraphs(2, true),
            'is_active' => $this->faker->boolean(90),
        ];
    }
}
