<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Models;

use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiPrompt;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Tests\TestCase;
use Illuminate\Database\QueryException;

/**
 * Class AiAssistantTest
 *
 * Confirms assistant model persistence, casts, and unique constraints align with Nexus schema rules.
 * PRD Reference: Atlas Nexus Overview — ai_assistants schema.
 */
class AiAssistantTest extends TestCase
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

    public function test_factory_persists_assistant_and_casts_attributes(): void
    {
        /** @var AiAssistant $assistant */
        $assistant = AiAssistant::factory()->create([
            'temperature' => 0.55,
            'top_p' => 0.75,
            'max_output_tokens' => 256,
            'current_prompt_id' => 99,
            'is_active' => true,
            'metadata' => ['tier' => 'pro'],
        ])->fresh();

        $this->assertInstanceOf(AiAssistant::class, $assistant);

        $this->assertSame('ai_assistants', $assistant->getTable());
        $this->assertIsFloat($assistant->temperature);
        $this->assertIsFloat($assistant->top_p);
        $this->assertSame(256, $assistant->max_output_tokens);
        $this->assertSame(99, $assistant->current_prompt_id);
        $this->assertTrue($assistant->is_active);
        $this->assertSame(['tier' => 'pro'], $assistant->metadata);
    }

    public function test_duplicate_slug_is_rejected(): void
    {
        $slug = 'duplicate-slug';

        AiAssistant::factory()->create(['slug' => $slug]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/unique/i');

        AiAssistant::factory()->create(['slug' => $slug]);
    }

    public function test_relationships_link_associated_records(): void
    {
        /** @var AiAssistant $assistant */
        $assistant = AiAssistant::factory()->create();
        /** @var AiPrompt $prompt */
        $prompt = AiPrompt::factory()->create([
            'assistant_id' => $assistant->id,
            'version' => 1,
        ]);
        $assistant->update(['current_prompt_id' => $prompt->id]);

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'prompt_id' => $prompt->id,
        ]);

        $assistant->update(['tools' => ['memory', 'search']]);
        $assistant->refresh();

        $this->assertTrue($assistant->prompts()->whereKey($prompt->id)->exists());
        $this->assertTrue($assistant->threads()->whereKey($thread->id)->exists());
        $this->assertTrue($assistant->currentPrompt?->is($prompt));
        $this->assertSame(['memory', 'search'], $assistant->tools);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
