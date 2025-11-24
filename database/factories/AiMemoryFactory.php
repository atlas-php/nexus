<?php

declare(strict_types=1);

namespace Atlas\Nexus\Database\Factories;

use Atlas\Nexus\Models\AiMemory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Class AiMemoryFactory
 *
 * Generates assistant memory records for testing and seeding contexts.
 *
 * @extends Factory<AiMemory>
 */
class AiMemoryFactory extends Factory
{
    protected $model = AiMemory::class;

    public function definition(): array
    {
        return [
            'group_id' => $this->faker->optional()->numberBetween(1, 50),
            'user_id' => $this->faker->numberBetween(1, 5_000),
            'assistant_id' => $this->faker->randomElement(['general-assistant', 'thread-manager', 'memory-extractor']),
            'thread_id' => $this->faker->optional()->numberBetween(1, 1_000),
            'content' => $this->faker->sentence(8),
            'source_message_ids' => $this->faker->optional()->randomElements(range(1, 20), 2),
        ];
    }
}
