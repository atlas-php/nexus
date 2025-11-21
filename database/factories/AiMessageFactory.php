<?php

declare(strict_types=1);

namespace Atlas\Nexus\Database\Factories;

use Atlas\Nexus\Enums\AiMessageContentType;
use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Models\AiMessage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Class AiMessageFactory
 *
 * Generates chat messages with ordered sequencing for testing thread persistence.
 * PRD Reference: Atlas Nexus Overview — ai_messages schema.
 */
class AiMessageFactory extends Factory
{
    protected $model = AiMessage::class;

    public function definition(): array
    {
        return [
            'thread_id' => $this->faker->numberBetween(1, 1_000),
            'user_id' => $this->faker->optional()->numberBetween(1, 5_000),
            'role' => $this->faker->randomElement(AiMessageRole::cases())->value,
            'content' => $this->faker->paragraph(),
            'content_type' => $this->faker->randomElement(AiMessageContentType::cases())->value,
            'sequence' => $this->faker->numberBetween(1, 500),
            'model' => $this->faker->optional()->word(),
            'tokens_in' => $this->faker->optional()->numberBetween(1, 8_000),
            'tokens_out' => $this->faker->optional()->numberBetween(1, 8_000),
            'provider_response_id' => $this->faker->optional()->uuid(),
            'metadata' => $this->faker->optional()->randomElement([
                ['tone' => 'friendly'],
                ['tone' => 'formal'],
            ]),
        ];
    }
}
