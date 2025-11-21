<?php

declare(strict_types=1);

namespace Atlas\Nexus\Database\Factories;

use Atlas\Nexus\Models\AiAssistantTool;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Class AiAssistantToolFactory
 *
 * Generates assistant-to-tool mapping records for validating tool access controls.
 * PRD Reference: Atlas Nexus Overview — ai_assistant_tool schema.
 *
 * @extends Factory<AiAssistantTool>
 */
class AiAssistantToolFactory extends Factory
{
    protected $model = AiAssistantTool::class;

    public function definition(): array
    {
        return [
            'assistant_id' => $this->faker->numberBetween(1, 1_000),
            'tool_id' => $this->faker->unique()->numberBetween(1, 1_000),
            'config' => $this->faker->optional()->randomElement([
                ['timeout' => 5],
                ['timeout' => 10],
            ]),
        ];
    }
}
