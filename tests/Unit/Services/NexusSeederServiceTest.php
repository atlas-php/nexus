<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services;

use Atlas\Nexus\Integrations\Prism\Tools\MemoryTool;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiAssistantTool;
use Atlas\Nexus\Models\AiTool;
use Atlas\Nexus\Services\Seeders\NexusSeederService;
use Atlas\Nexus\Tests\Fixtures\DummyNexusSeeder;
use Atlas\Nexus\Tests\TestCase;

/**
 * Class NexusSeederServiceTest
 *
 * Verifies built-in seeding is idempotent and supports consumer extension.
 */
class NexusSeederServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadPackageMigrations($this->migrationPath());
        $this->runPendingCommand('migrate:fresh', [
            '--path' => $this->migrationPath(),
            '--realpath' => true,
        ])->run();
    }

    public function test_it_seeds_memory_tool_and_is_idempotent(): void
    {
        /** @var AiAssistant $assistant */
        $assistant = AiAssistant::factory()->create(['slug' => 'seedable']);

        $service = $this->app->make(NexusSeederService::class);

        $service->run();
        $service->run();

        $toolCount = AiTool::query()->where('slug', MemoryTool::SLUG)->count();
        $this->assertSame(1, $toolCount);

        $pivot = AiAssistantTool::query()
            ->where('assistant_id', $assistant->id)
            ->whereHas('tool', fn ($query) => $query->where('slug', MemoryTool::SLUG))
            ->first();

        $this->assertInstanceOf(AiAssistantTool::class, $pivot);
    }

    public function test_consumers_can_extend_seeders(): void
    {
        config()->set('atlas-nexus.seeders', [
            DummyNexusSeeder::class,
        ]);

        DummyNexusSeeder::$runs = 0;

        $this->app->make(NexusSeederService::class)->run();

        $this->assertSame(1, DummyNexusSeeder::$runs);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
