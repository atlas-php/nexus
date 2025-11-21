<?php

declare(strict_types=1);

namespace Atlas\Nexus\Database\Factories;

use Atlas\Nexus\Models\AiTool;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * Class AiToolFactory
 *
 * Provides seeded tool records with schemas and handler references for assistant integration tests.
 * PRD Reference: Atlas Nexus Overview — ai_tools schema.
 *
 * @extends Factory<AiTool>
 */
class AiToolFactory extends Factory
{
    protected $model = AiTool::class;

    public function definition(): array
    {
        $name = ucfirst($this->faker->words(2, true));

        return [
            'slug' => Str::slug($name).'-'.$this->faker->unique()->numberBetween(1, 9_999),
            'name' => $name,
            'description' => $this->faker->optional()->sentence(10),
            'schema' => [
                'type' => 'object',
                'properties' => [
                    'query' => ['type' => 'string'],
                    'limit' => ['type' => 'integer', 'minimum' => 1],
                ],
                'required' => ['query'],
            ],
            'handler_class' => $this->faker->randomElement([
                'App\\Tools\\SearchTool',
                'App\\Tools\\WeatherTool',
                'App\\Tools\\LookupTool',
            ]),
            'is_active' => $this->faker->boolean(95),
        ];
    }
}
