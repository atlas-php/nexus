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
 * Ensures the seed command executes configured seeders.
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

        $command = new NexusSeedCommand;
        $command->setLaravel($this->app);
        $command->setApplication(new Application($this->app, $this->app['events'], 'testing'));

        $status = $command->run(new ArrayInput([]), new NullOutput);
        $this->assertSame(0, $status);

        $webAssistant = AiAssistant::query()->where('slug', 'web-summarizer')->first();
        $threadManagerAssistant = AiAssistant::query()->where('slug', 'thread-manager')->first();

        $this->assertNotNull($webAssistant);
        $this->assertNotNull($threadManagerAssistant);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
