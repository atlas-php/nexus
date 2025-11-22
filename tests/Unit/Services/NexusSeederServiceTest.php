<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services;

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

    public function test_it_seeds_built_in_assistants_and_is_idempotent(): void
    {
        $service = $this->app->make(NexusSeederService::class);

        $service->run();
        $service->run();

        $webAssistant = \Atlas\Nexus\Models\AiAssistant::query()->where('slug', 'web-summarizer')->first();
        $threadAssistant = \Atlas\Nexus\Models\AiAssistant::query()->where('slug', 'thread-manager')->first();

        $this->assertNotNull($webAssistant);
        $this->assertNotNull($threadAssistant);
        $this->assertTrue($webAssistant->is_hidden);
        $this->assertTrue($threadAssistant->is_hidden);
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
