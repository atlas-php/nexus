<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services;

use Atlas\Nexus\Services\Seeders\DefaultAssistantSeeder;
use Atlas\Nexus\Support\Assistants\DefaultGeneralAssistantDefaults;
use Atlas\Nexus\Support\Assistants\DefaultHumanAssistantDefaults;
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

    public function test_it_seeds_default_assistants_and_prompts(): void
    {
        $seeder = $this->app->make(DefaultAssistantSeeder::class);

        $seeder->seed();
        $seeder->seed();

        $general = \Atlas\Nexus\Models\AiAssistant::query()
            ->with('currentPrompt')
            ->where('slug', DefaultGeneralAssistantDefaults::ASSISTANT_SLUG)
            ->first();

        $human = \Atlas\Nexus\Models\AiAssistant::query()
            ->with('currentPrompt')
            ->where('slug', DefaultHumanAssistantDefaults::ASSISTANT_SLUG)
            ->first();

        $this->assertNotNull($general);
        $this->assertFalse($general->is_hidden);
        $this->assertNotNull($general->currentPrompt);
        $this->assertSame(DefaultGeneralAssistantDefaults::SYSTEM_PROMPT, $general->currentPrompt->system_prompt);
        $this->assertSame($general->id, $general->currentPrompt->assistant_id);

        $this->assertNotNull($human);
        $this->assertFalse($human->is_hidden);
        $this->assertNotNull($human->currentPrompt);
        $this->assertSame(DefaultHumanAssistantDefaults::SYSTEM_PROMPT, $human->currentPrompt->system_prompt);
        $this->assertSame($human->id, $human->currentPrompt->assistant_id);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
