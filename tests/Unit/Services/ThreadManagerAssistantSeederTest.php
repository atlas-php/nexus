<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services;

use Atlas\Nexus\Services\Seeders\ThreadManagerAssistantSeeder;
use Atlas\Nexus\Support\Threads\ThreadManagerDefaults;
use Atlas\Nexus\Tests\TestCase;

/**
 * Class ThreadManagerAssistantSeederTest
 *
 * Ensures the thread manager assistant and prompt are created when enabled.
 */
class ThreadManagerAssistantSeederTest extends TestCase
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

    public function test_it_seeds_thread_manager_assistant_and_prompt(): void
    {
        $seeder = $this->app->make(ThreadManagerAssistantSeeder::class);

        $seeder->seed();
        $seeder->seed();

        $assistant = \Atlas\Nexus\Models\AiAssistant::query()
            ->with('currentPrompt')
            ->where('slug', ThreadManagerDefaults::ASSISTANT_SLUG)
            ->first();

        $this->assertNotNull($assistant);
        $this->assertNotNull($assistant->currentPrompt);
        $this->assertSame(ThreadManagerDefaults::PROMPT_LABEL, $assistant->currentPrompt->label);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
