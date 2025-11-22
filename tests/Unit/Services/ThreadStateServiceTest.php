<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services;

use Atlas\Nexus\Enums\AiMemoryOwnerType;
use Atlas\Nexus\Enums\AiMessageContentType;
use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Integrations\Prism\Tools\MemoryTool;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiPrompt;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Seeders\NexusSeederService;
use Atlas\Nexus\Services\Threads\ThreadStateService;
use Atlas\Nexus\Tests\Fixtures\StubTool;
use Atlas\Nexus\Tests\Fixtures\TestUser;
use Atlas\Nexus\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Class ThreadStateServiceTest
 *
 * Validates thread state aggregation for prompts, messages, memories, and tools.
 */
class ThreadStateServiceTest extends TestCase
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

    public function test_it_builds_thread_state_with_contextual_resources(): void
    {
        config()->set('atlas-nexus.tools.registry', [
            MemoryTool::KEY => \Atlas\Nexus\Integrations\Prism\Tools\MemoryTool::class,
            'calendar_lookup' => StubTool::class,
        ]);

        $assistant = AiAssistant::factory()->create([
            'slug' => 'state-assistant',
            'tools' => ['calendar_lookup'],
        ]);
        $prompt = AiPrompt::factory()->create([
            'assistant_id' => $assistant->id,
            'version' => 1,
            'system_prompt' => 'Stay helpful.',
        ]);

        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'prompt_id' => $prompt->id,
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'role' => AiMessageRole::USER->value,
            'sequence' => 1,
            'content_type' => AiMessageContentType::TEXT->value,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'role' => AiMessageRole::ASSISTANT->value,
            'sequence' => 2,
            'status' => AiMessageStatus::PROCESSING->value,
        ]);

        $memory = AiMemory::factory()->create([
            'assistant_id' => $assistant->id,
            'thread_id' => $thread->id,
            'owner_type' => AiMemoryOwnerType::USER->value,
            'owner_id' => $thread->user_id,
            'kind' => 'fact',
            'content' => 'User prefers morning updates.',
        ]);

        $this->app->make(NexusSeederService::class)->run();

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);

        $toolKeys = $state->tools->map(fn ($definition) => $definition->key())->all();

        $this->assertSame($prompt->id, $state->prompt?->id);
        $this->assertCount(1, $state->messages);
        $this->assertTrue($state->memories->contains('id', $memory->id));
        $this->assertTrue(in_array('calendar_lookup', $toolKeys, true));
        $this->assertTrue(in_array(MemoryTool::KEY, $toolKeys, true), 'Available keys: '.implode(', ', $toolKeys));
    }

    public function test_it_can_exclude_memory_tool_when_requested(): void
    {
        $assistant = AiAssistant::factory()->create(['slug' => 'state-assistant-disabled']);
        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
        ]);

        $this->app->make(NexusSeederService::class)->run();

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread, false);

        $this->assertFalse($state->tools->contains(fn ($definition) => $definition->key() === MemoryTool::KEY));
    }

    public function test_it_stores_and_reuses_prompt_snapshot_when_freeze_enabled(): void
    {
        config()->set('atlas-nexus.prompts.freeze_thread', true);
        config()->set('auth.providers.users.model', TestUser::class);
        config()->set('auth.model', TestUser::class);

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        $user = TestUser::query()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);

        $assistant = AiAssistant::factory()->create(['slug' => 'frozen-assistant']);
        $prompt = AiPrompt::factory()->create([
            'assistant_id' => $assistant->id,
            'version' => 1,
            'system_prompt' => 'Hello {USER.NAME}',
        ]);

        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'prompt_id' => $prompt->id,
            'user_id' => $user->id,
        ]);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);

        $this->assertNotNull($state->promptSnapshot);
        $this->assertSame('Hello Ada Lovelace', $state->systemPrompt);
        $this->assertSame('Hello Ada Lovelace', $state->promptSnapshot->renderedSystemPrompt);

        $prompt->update(['system_prompt' => 'Hi {USER.NAME}']);

        $refreshed = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $refreshed);
        $this->assertNotNull($refreshed->prompt_snapshot);

        $updatedState = $this->app->make(ThreadStateService::class)->forThread($refreshed);

        $this->assertSame('Hello Ada Lovelace', $updatedState->systemPrompt);
        $this->assertNotNull($refreshed->prompt_snapshot);
        $this->assertSame('Hello Ada Lovelace', $refreshed->prompt_snapshot['rendered_system_prompt'] ?? null);
    }

    public function test_it_rerenders_prompt_when_freeze_is_disabled(): void
    {
        config()->set('atlas-nexus.prompts.freeze_thread', false);
        config()->set('auth.providers.users.model', TestUser::class);
        config()->set('auth.model', TestUser::class);

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        $user = TestUser::query()->create([
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
        ]);

        $assistant = AiAssistant::factory()->create(['slug' => 'live-assistant']);
        $prompt = AiPrompt::factory()->create([
            'assistant_id' => $assistant->id,
            'version' => 1,
            'system_prompt' => 'Hello {USER.NAME}',
        ]);

        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'prompt_id' => $prompt->id,
            'user_id' => $user->id,
        ]);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);

        $this->assertNull($state->promptSnapshot);
        $this->assertSame('Hello Grace Hopper', $state->systemPrompt);

        $user->update(['name' => 'Grace M. Hopper']);
        $prompt->update(['system_prompt' => 'Welcome back {USER.NAME}']);

        $refreshed = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $refreshed);
        $this->assertNull($refreshed->prompt_snapshot);

        $liveState = $this->app->make(ThreadStateService::class)->forThread($refreshed);

        $this->assertSame('Welcome back Grace M. Hopper', $liveState->systemPrompt);
    }

    public function test_it_resolves_configured_provider_tools_for_assistant(): void
    {
        config()->set('atlas-nexus.provider_tools', [
            'web_search' => ['filters' => ['allowed_domains' => ['example.com']]],
            'site_search' => ['filters' => ['allowed_domains' => ['example.org']]],
        ]);

        $assistant = AiAssistant::factory()->create([
            'slug' => 'provider-tools',
            'provider_tools' => ['web_search', 'missing_tool'],
        ]);
        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
        ]);

        $this->app->make(NexusSeederService::class)->run();

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);

        $keys = $state->providerTools->map(fn ($definition) => $definition->key())->all();

        $this->assertSame(['web_search'], $keys);
        $this->assertSame(['filters' => ['allowed_domains' => ['example.com']]], $state->providerTools->first()?->options());
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
