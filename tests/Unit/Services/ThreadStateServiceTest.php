<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services;

use Atlas\Nexus\Enums\AiMemoryOwnerType;
use Atlas\Nexus\Enums\AiMessageContentType;
use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Integrations\Prism\Tools\MemoryTool;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiAssistantPrompt;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiMessage;
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
        $prompt = AiAssistantPrompt::factory()->create([
            'assistant_id' => $assistant->id,
            'system_prompt' => 'Stay helpful.',
        ]);

        $assistant->update(['current_prompt_id' => $prompt->id]);

        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'assistant_prompt_id' => $prompt->id,
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

    public function test_it_ignores_prompts_from_other_assistants(): void
    {
        /** @var AiAssistant $assistant */
        $assistant = AiAssistant::factory()->create(['slug' => 'primary-assistant']);
        /** @var AiAssistantPrompt $ownedPrompt */
        $ownedPrompt = AiAssistantPrompt::factory()->create([
            'assistant_id' => $assistant->id,
            'system_prompt' => 'Primary prompt',
        ]);
        $assistant->update(['current_prompt_id' => $ownedPrompt->id]);

        /** @var AiAssistant $foreignAssistant */
        $foreignAssistant = AiAssistant::factory()->create();
        /** @var AiAssistantPrompt $foreignPrompt */
        $foreignPrompt = AiAssistantPrompt::factory()->create([
            'assistant_id' => $foreignAssistant->id,
            'system_prompt' => 'Foreign prompt',
        ]);

        $assistant->update(['current_prompt_id' => $ownedPrompt->id]);

        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'assistant_prompt_id' => $ownedPrompt->id,
        ]);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);

        $this->assertNotNull($state->prompt);
        $this->assertSame($ownedPrompt->id, $state->prompt->id);
        $this->assertNotSame($foreignPrompt->id, $state->prompt->id);
    }

    public function test_thread_prefers_assistant_prompt_override(): void
    {
        /** @var AiAssistant $assistant */
        $assistant = AiAssistant::factory()->create(['slug' => 'override-assistant']);
        $threadPrompt = AiAssistantPrompt::factory()->create([
            'assistant_id' => $assistant->id,
            'system_prompt' => 'Thread-specific prompt',
        ]);
        $assistantPrompt = AiAssistantPrompt::factory()->create([
            'assistant_id' => $assistant->id,
            'system_prompt' => 'Assistant current prompt',
        ]);

        $assistant->update(['current_prompt_id' => $assistantPrompt->id]);

        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'assistant_prompt_id' => $threadPrompt->id,
        ]);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);

        $this->assertSame($threadPrompt->id, $state->prompt?->id);
    }

    public function test_it_renders_prompt_with_latest_values(): void
    {
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
        $prompt = AiAssistantPrompt::factory()->create([
            'assistant_id' => $assistant->id,
            'system_prompt' => 'Hello {USER.NAME}',
        ]);

        $assistant->update(['current_prompt_id' => $prompt->id]);

        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'assistant_prompt_id' => $prompt->id,
            'user_id' => $user->id,
        ]);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);

        $this->assertSame('Hello Grace Hopper', $state->systemPrompt);

        $user->update(['name' => 'Grace M. Hopper']);
        $prompt->update(['system_prompt' => 'Welcome back {USER.NAME}']);

        $liveThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $liveThread);

        $liveState = $this->app->make(ThreadStateService::class)->forThread($liveThread);

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

    public function test_provider_tools_do_not_enable_regular_tools_with_same_key(): void
    {
        /** @var AiAssistant $assistant */
        $assistant = AiAssistant::factory()->create([
            'slug' => 'provider-only',
            'provider_tools' => ['web_search'],
            'tools' => [],
        ]);

        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
        ]);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);

        $toolKeys = $state->tools->map(fn ($definition) => $definition->key())->all();
        $providerKeys = $state->providerTools->map(fn ($definition) => $definition->key())->all();

        $this->assertNotContains('web_search', $toolKeys);
        $this->assertSame(['web_search'], $providerKeys);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
