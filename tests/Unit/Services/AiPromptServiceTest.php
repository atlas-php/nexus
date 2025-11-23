<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services;

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

    public function test_edit_updates_prompt_inline(): void
    {
        $service = $this->app->make(AiPromptService::class);

        /** @var AiPrompt $prompt */
        $prompt = $service->create([
            'version' => 1,
            'label' => 'Base Prompt',
            'system_prompt' => 'Hello world',
            'is_active' => true,
        ]);

        $updated = $service->edit($prompt, ['system_prompt' => 'Updated prompt text']);

        $this->assertSame($prompt->id, $updated->id);
        $this->assertSame('Updated prompt text', $updated->system_prompt);
        $this->assertSame($prompt->id, $updated->original_prompt_id);
    }

    public function test_edit_can_create_new_version(): void
    {
        $service = $this->app->make(AiPromptService::class);

        /** @var AiPrompt $prompt */
        $prompt = $service->create([
            'version' => 1,
            'label' => 'Base Prompt',
            'system_prompt' => 'Initial',
            'is_active' => true,
        ]);

        $newVersion = $service->edit($prompt, [
            'label' => 'Base Prompt v2',
            'system_prompt' => 'Second iteration',
        ], true);

        $this->assertNotSame($prompt->id, $newVersion->id);
        $this->assertSame(2, $newVersion->version);
        $this->assertSame('Second iteration', $newVersion->system_prompt);
        $this->assertSame('Base Prompt v2', $newVersion->label);
        $this->assertSame($prompt->id, $newVersion->original_prompt_id);
        $this->assertSame('Base Prompt', $prompt->refresh()->label);
        $this->assertSame($prompt->id, $prompt->original_prompt_id);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
