<?php

declare(strict_types=1);

namespace Atlas\Nexus\Database\Factories;

use Atlas\Nexus\Models\AiAssistant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Class AiAssistantFactory
 *
 * Generates AI assistant records for Nexus feature and integration testing.
 * PRD Reference: Atlas Nexus Overview — ai_assistants schema.
 *
 * @extends Factory<AiAssistant>
 */
class AiAssistantFactory extends Factory
{
    protected $model = AiAssistant::class;

    public function definition(): array
    {
        $temperature = $this->faker->randomFloat(2, 0, 1);
        $topP = $this->faker->randomFloat(2, 0, 1);

        return [
            'slug' => $this->faker->unique()->slug(),
            'name' => ucfirst($this->faker->words(3, true)),
            'description' => $this->faker->optional()->sentence(12),
            'default_model' => $this->faker->optional()->word(),
            'temperature' => $temperature,
            'top_p' => $topP,
            'max_output_tokens' => $this->faker->optional()->numberBetween(1, 4096),
            'current_prompt_id' => $this->faker->optional()->numberBetween(1, 5_000),
            'is_active' => $this->faker->boolean(90),
            'is_hidden' => false,
            'provider_tools' => ['web_search'],
            'tools' => ['memory'],
            'metadata' => $this->faker->optional()->randomElement([
                ['category' => 'support'],
                ['category' => 'sales'],
                ['category' => 'ops'],
            ]),
        ];
    }
}
