<?php

declare(strict_types=1);

namespace Atlas\Nexus\Database\Factories;

use Atlas\Nexus\Enums\AiToolRunStatus;
use Atlas\Nexus\Models\AiToolRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Class AiToolRunFactory
 *
 * Produces tool run records covering queued, running, and completed lifecycle states keyed by tool name.
 * PRD Reference: Atlas Nexus Overview — ai_message_tools schema.
 *
 * @extends Factory<AiToolRun>
 */
class AiToolRunFactory extends Factory
{
    protected $model = AiToolRun::class;

    public function definition(): array
    {
        /** @var AiToolRunStatus $status */
        $status = $this->faker->randomElement(AiToolRunStatus::cases());

        return [
            'tool_key' => $this->faker->randomElement(['fetch_more_context', 'calendar_lookup']),
            'thread_id' => $this->faker->numberBetween(1, 1_000),
            'assistant_key' => 'general-assistant',
            'group_id' => null,
            'assistant_message_id' => $this->faker->numberBetween(1, 5_000),
            'call_index' => $this->faker->numberBetween(0, 10),
            'input_args' => ['query' => $this->faker->sentence(3)],
            'status' => $status->value,
            'response_output' => $this->faker->optional()->randomElement([
                ['result' => 'ok'],
                ['items' => []],
            ]),
            'metadata' => $this->faker->optional()->randomElement([
                ['duration_ms' => $this->faker->numberBetween(10, 500)],
            ]),
            'error_message' => $status === AiToolRunStatus::FAILED ? $this->faker->sentence() : null,
            'started_at' => $this->faker->optional()->dateTimeBetween('-1 hour'),
            'finished_at' => $this->faker->optional()->dateTimeBetween('-30 minutes'),
        ];
    }
}
