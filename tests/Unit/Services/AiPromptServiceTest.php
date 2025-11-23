<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services;

use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiPrompt;
use Atlas\Nexus\Services\Models\AiPromptService;
use Atlas\Nexus\Tests\TestCase;

/**
 * Class AiPromptServiceTest
 *
 * Ensures prompt edits can be applied inline or as auto-versioned copies.
 */
class AiPromptServiceTest extends TestCase
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

    public function test_create_assigns_first_version_and_lineage(): void
    {
        $service = $this->app->make(AiPromptService::class);
        /** @var AiAssistant $assistant */
        $assistant = AiAssistant::factory()->create();

        /** @var AiPrompt $prompt */
        $prompt = $service->create([
            'assistant_id' => $assistant->id,
            'system_prompt' => 'Hello world',
            'is_active' => true,
        ]);

        $this->assertSame(1, $prompt->version);
        $this->assertSame($assistant->id, $prompt->assistant_id);
        $this->assertSame($prompt->id, $prompt->original_prompt_id);
    }

    public function test_edit_always_generates_new_version(): void
    {
        $service = $this->app->make(AiPromptService::class);
        /** @var AiAssistant $assistant */
        $assistant = AiAssistant::factory()->create();

        $prompt = $service->create([
            'assistant_id' => $assistant->id,
            'system_prompt' => 'Initial',
            'is_active' => true,
        ]);

        $newVersion = $service->edit($prompt, [
            'system_prompt' => 'Second iteration',
        ]);

        $this->assertNotSame($prompt->id, $newVersion->id);
        $this->assertSame(2, $newVersion->version);
        $this->assertSame('Second iteration', $newVersion->system_prompt);
        $this->assertSame($prompt->id, $newVersion->original_prompt_id);

        $anotherVersion = $service->edit($newVersion, [
            'system_prompt' => 'Third iteration',
        ]);

        $this->assertSame(3, $anotherVersion->version);
        $this->assertSame($prompt->original_prompt_id, $anotherVersion->original_prompt_id);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
