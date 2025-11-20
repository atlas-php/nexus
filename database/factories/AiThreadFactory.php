<?php

declare(strict_types=1);

namespace Atlas\Nexus\Database\Factories;

use Atlas\Nexus\Models\AiThread;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Class AiThreadFactory
 *
 * Creates conversation thread records spanning user and tool originators.
 * PRD Reference: Atlas Nexus Overview — ai_threads schema.
 */
class AiThreadFactory extends Factory
{
    protected $model = AiThread::class;

    public function definition(): array
    {
        return [
            'assistant_id' => $this->faker->numberBetween(1, 1_000),
            'user_id' => $this->faker->numberBetween(1, 5_000),
            'type' => $this->faker->randomElement(['user', 'tool']),
            'parent_thread_id' => $this->faker->optional()->numberBetween(1, 500),
            'parent_tool_run_id' => $this->faker->optional()->numberBetween(1, 500),
            'title' => $this->faker->optional()->sentence(6),
            'status' => $this->faker->randomElement(['open', 'archived', 'closed']),
            'prompt_id' => $this->faker->optional()->numberBetween(1, 5_000),
            'summary' => $this->faker->optional()->paragraph(),
            'last_message_at' => $this->faker->optional()->dateTimeBetween('-2 weeks'),
            'metadata' => $this->faker->optional()->randomElement([
                ['topic' => 'support'],
                ['topic' => 'sales'],
            ]),
        ];
    }
}
