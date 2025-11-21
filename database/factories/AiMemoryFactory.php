<?php

declare(strict_types=1);

namespace Atlas\Nexus\Database\Factories;

use Atlas\Nexus\Enums\AiMemoryOwnerType;
use Atlas\Nexus\Models\AiMemory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Class AiMemoryFactory
 *
 * Creates reusable memory entries tied to owners and provenance for retrieval tests.
 * PRD Reference: Atlas Nexus Overview — ai_memories schema.
 *
 * @extends Factory<AiMemory>
 */
class AiMemoryFactory extends Factory
{
    protected $model = AiMemory::class;

    public function definition(): array
    {
        return [
            'owner_type' => $this->faker->randomElement(AiMemoryOwnerType::cases())->value,
            'owner_id' => $this->faker->numberBetween(1, 5_000),
            'group_id' => null,
            'assistant_id' => $this->faker->optional()->numberBetween(1, 1_000),
            'thread_id' => $this->faker->optional()->numberBetween(1, 1_000),
            'source_message_id' => $this->faker->optional()->numberBetween(1, 5_000),
            'source_tool_run_id' => $this->faker->optional()->numberBetween(1, 5_000),
            'kind' => $this->faker->randomElement(['fact', 'preference', 'summary', 'task', 'constraint']),
            'content' => $this->faker->sentences(2, true),
            'metadata' => $this->faker->optional()->randomElement([
                ['context' => 'billing'],
                ['context' => 'support'],
            ]),
        ];
    }
}
