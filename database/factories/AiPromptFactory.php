<?php

declare(strict_types=1);

namespace Atlas\Nexus\Database\Factories;

use Atlas\Nexus\Models\AiPrompt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Class AiPromptFactory
 *
 * Produces versioned prompt records for assistants with optional user ownership.
 * PRD Reference: Atlas Nexus Overview — ai_prompts schema.
 *
 * @extends Factory<AiPrompt>
 */
class AiPromptFactory extends Factory
{
    protected $model = AiPrompt::class;

    public function definition(): array
    {
        return [
            'user_id' => $this->faker->optional()->numberBetween(1, 5_000),
            'version' => $this->faker->unique()->numberBetween(1, 2_147_483_647),
            'original_prompt_id' => null,
            'label' => $this->faker->optional()->sentence(3),
            'system_prompt' => $this->faker->paragraphs(2, true),
            'is_active' => $this->faker->boolean(90),
        ];
    }
}
