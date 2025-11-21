<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Console;

use Atlas\Nexus\Console\Commands\NexusSeedCommand;
use Atlas\Nexus\Integrations\Prism\Tools\MemoryTool;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Tests\TestCase;
use Illuminate\Console\Application;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\NullOutput;

/**
 * Class NexusSeedCommandTest
 *
 * Ensures the seed command executes the configured seeder pipeline.
 */
class NexusSeedCommandTest extends TestCase
{
    public function test_command_runs_seed_service(): void
    {
        $this->loadPackageMigrations($this->migrationPath());
        $this->runPendingCommand('migrate:fresh', [
            '--path' => $this->migrationPath(),
            '--realpath' => true,
        ])->run();

        /** @var AiAssistant $assistant */
        $assistant = AiAssistant::factory()->create(['tools' => []]);
        $this->assertSame([], $assistant->tools);

        $command = new NexusSeedCommand;
        $command->setLaravel($this->app);
        $command->setApplication(new Application($this->app, $this->app['events'], 'testing'));

        $status = $command->run(new ArrayInput([]), new NullOutput);
        $this->assertSame(0, $status);

        $assistant->refresh();
        $this->assertContains(MemoryTool::KEY, $assistant->tools ?? []);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
