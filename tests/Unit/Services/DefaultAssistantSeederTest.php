<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services;

use Atlas\Nexus\Services\Seeders\DefaultAssistantSeeder;
use Atlas\Nexus\Support\Assistants\DefaultAssistantDefaults;
use Atlas\Nexus\Tests\TestCase;

/**
 * Class DefaultAssistantSeederTest
 *
 * Ensures the default assistant and prompt are created and activated.
 */
class DefaultAssistantSeederTest extends TestCase
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

    public function test_it_seeds_default_assistant_and_prompt(): void
    {
        $seeder = $this->app->make(DefaultAssistantSeeder::class);

        $seeder->seed();
        $seeder->seed();

        $assistant = \Atlas\Nexus\Models\AiAssistant::query()
            ->with('currentPrompt')
            ->where('slug', DefaultAssistantDefaults::ASSISTANT_SLUG)
            ->first();

        $this->assertNotNull($assistant);
        $this->assertFalse($assistant->is_hidden);
        $this->assertNotNull($assistant->currentPrompt);
        $this->assertSame(DefaultAssistantDefaults::PROMPT_LABEL, $assistant->currentPrompt->label);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
