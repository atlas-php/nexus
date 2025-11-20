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
 */
class AiPromptFactory extends Factory
{
    protected $model = AiPrompt::class;

    public function definition(): array
    {
        return [
            'user_id' => $this->faker->optional()->numberBetween(1, 5_000),
            'assistant_id' => $this->faker->numberBetween(1, 1_000),
            'version' => $this->faker->unique()->numberBetween(1, 2_147_483_647),
            'label' => $this->faker->optional()->sentence(3),
            'system_prompt' => $this->faker->paragraphs(2, true),
            'variables_schema' => $this->faker->optional()->randomElement([
                ['type' => 'object', 'properties' => ['topic' => ['type' => 'string']]],
                ['type' => 'object', 'properties' => ['priority' => ['type' => 'integer']]],
            ]),
            'is_active' => $this->faker->boolean(90),
        ];
    }
}
