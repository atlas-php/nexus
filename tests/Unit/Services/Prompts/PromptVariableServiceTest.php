<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services\Prompts;

use Atlas\Nexus\Enums\AiMemoryOwnerType;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiAssistantPrompt;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Prompts\PromptVariableRegistry;
use Atlas\Nexus\Services\Prompts\PromptVariableService;
use Atlas\Nexus\Services\Threads\ThreadStateService;
use Atlas\Nexus\Support\Prompts\PromptVariableContext;
use Atlas\Nexus\Support\Prompts\Variables\ThreadPromptVariables;
use Atlas\Nexus\Support\Prompts\Variables\UserPromptVariables;
use Atlas\Nexus\Tests\Fixtures\CustomPromptVariable;
use Atlas\Nexus\Tests\Fixtures\TestUser;
use Atlas\Nexus\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

/**
 * Class PromptVariableServiceTest
 *
 * Verifies configured prompt variables are applied to system prompts with support for custom providers and inline overrides.
 */
class PromptVariableServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('auth.providers.users.model', TestUser::class);
        config()->set('auth.model', TestUser::class);

        $this->loadPackageMigrations($this->migrationPath());
        $this->runPendingCommand('migrate:fresh', [
            '--path' => $this->migrationPath(),
            '--realpath' => true,
        ])->run();

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->timestamps();
        });

        $this->resetVariableServices();
    }

    public function test_it_replaces_builtin_user_variables(): void
    {
        $user = TestUser::query()->create([
            'name' => 'Ada Lovelace',
            'email' => 'ada@example.com',
        ]);

        $assistant = AiAssistant::factory()->create(['slug' => 'prompt-variables']);
        $prompt = AiAssistantPrompt::factory()->create([
            'assistant_id' => $assistant->id,
            'system_prompt' => 'Hello {USER.NAME} ({USER.EMAIL})',
        ]);

        $assistant->update(['current_prompt_id' => $prompt->id]);

        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'user_id' => $user->id,
        ]);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);
        $context = new PromptVariableContext($state, $prompt, $assistant);

        $rendered = $this->app->make(PromptVariableService::class)
            ->apply($prompt->system_prompt, $context);

        $this->assertSame('Hello Ada Lovelace (ada@example.com)', $rendered);
    }

    public function test_it_merges_custom_provider_and_inline_variables(): void
    {
        config()->set('atlas-nexus.prompts.variables', [
            ThreadPromptVariables::class,
            UserPromptVariables::class,
            CustomPromptVariable::class,
        ]);

        $this->resetVariableServices();

        $user = TestUser::query()->create([
            'name' => 'Grace Hopper',
            'email' => 'grace@example.com',
        ]);

        $assistant = AiAssistant::factory()->create(['slug' => 'prompt-variables-custom']);
        $prompt = AiAssistantPrompt::factory()->create([
            'assistant_id' => $assistant->id,
            'system_prompt' => 'Thread {THREAD.ID}: {USER.NAME} - {INLINE.FLAG}',
        ]);

        $assistant->update(['current_prompt_id' => $prompt->id]);

        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'user_id' => $user->id,
        ]);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);
        $context = new PromptVariableContext($state, $prompt, $assistant);

        $rendered = $this->app->make(PromptVariableService::class)
            ->apply($prompt->system_prompt, $context, ['INLINE.FLAG' => 'enabled']);

        $this->assertSame(
            'Thread '.$thread->id.': Grace Hopper - enabled',
            $rendered
        );
    }

    public function test_it_resolves_thread_variables_and_datetime(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-05-01 12:34:56', 'UTC'));

        $user = TestUser::query()->create([
            'name' => 'Thread Owner',
            'email' => 'thread-owner@example.com',
        ]);

        $assistant = AiAssistant::factory()->create(['slug' => 'thread-variables']);
        $prompt = AiAssistantPrompt::factory()->create([
            'assistant_id' => $assistant->id,
            'system_prompt' => 'Thread {THREAD.ID}: {THREAD.TITLE} | {THREAD.SUMMARY} / {THREAD.LONG_SUMMARY} @ {DATETIME} Recents {THREAD.RECENT.IDS}',
        ]);

        $assistant->update(['current_prompt_id' => $prompt->id]);

        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'user_id' => $user->id,
            'title' => 'Quarterly Planning Sync',
            'summary' => 'Plan quarterly roadmap',
            'long_summary' => 'Plan quarterly roadmap with product and engineering alignment',
            'last_message_at' => Carbon::now(),
        ]);

        $recentThreads = collect();

        foreach (range(1, 6) as $minutes) {
            $recentThreads->push(
                AiThread::factory()->create([
                    'assistant_id' => $assistant->id,
                    'user_id' => $user->id,
                    'last_message_at' => Carbon::now()->subMinutes($minutes),
                ])
            );
        }

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);
        $context = new PromptVariableContext($state, $prompt, $assistant);

        $rendered = $this->app->make(PromptVariableService::class)
            ->apply($prompt->system_prompt, $context);

        $expectedRecentIds = $recentThreads
            ->sortByDesc(function (AiThread $model): int {
                $timestamp = $model->last_message_at ?? $model->updated_at ?? $model->created_at;

                return $timestamp?->getTimestamp() ?? 0;
            })
            ->take(5)
            ->pluck('id')
            ->implode(', ');

        $this->assertSame(
            'Thread '.$thread->id.': Quarterly Planning Sync | Plan quarterly roadmap / Plan quarterly roadmap with product and engineering alignment @ 2024-05-01T12:34:56+00:00 Recents '.$expectedRecentIds,
            $rendered
        );

        Carbon::setTestNow();
    }

    public function test_recent_thread_ids_returns_none_when_empty(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-05-01 12:34:56', 'UTC'));

        $user = TestUser::query()->create([
            'name' => 'Thread Owner',
            'email' => 'thread-owner@example.com',
        ]);

        $assistant = AiAssistant::factory()->create(['slug' => 'thread-variables-none']);
        $prompt = AiAssistantPrompt::factory()->create([
            'assistant_id' => $assistant->id,
            'system_prompt' => 'Recent threads: {THREAD.RECENT.IDS}',
        ]);

        $assistant->update(['current_prompt_id' => $prompt->id]);

        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'user_id' => $user->id,
            'title' => 'Only Thread',
        ]);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);
        $context = new PromptVariableContext($state, $prompt, $assistant);

        $rendered = $this->app->make(PromptVariableService::class)
            ->apply($prompt->system_prompt, $context);

        $this->assertSame('Recent threads: None', $rendered);

        Carbon::setTestNow();
    }

    public function test_memory_context_variable_is_available_when_memories_exist(): void
    {
        Carbon::setTestNow(Carbon::parse('2024-01-01 12:00:00', 'UTC'));

        $user = TestUser::query()->create([
            'name' => 'Memory Owner',
            'email' => 'memory-owner@example.com',
        ]);

        $assistant = AiAssistant::factory()->create(['slug' => 'memory-variable']);
        $prompt = AiAssistantPrompt::factory()->create([
            'assistant_id' => $assistant->id,
            'system_prompt' => 'Memories -> {MEMORY.CONTEXT}',
        ]);

        $assistant->update(['current_prompt_id' => $prompt->id]);

        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'user_id' => $user->id,
        ]);

        AiMemory::factory()->create([
            'assistant_id' => $assistant->id,
            'thread_id' => $thread->id,
            'owner_type' => AiMemoryOwnerType::USER->value,
            'owner_id' => $thread->user_id,
            'kind' => 'fact',
            'content' => 'User lives in Toronto.',
            'created_at' => now()->addMinute(),
            'updated_at' => now()->addMinute(),
        ]);

        AiMemory::factory()->create([
            'assistant_id' => $assistant->id,
            'thread_id' => $thread->id,
            'owner_type' => AiMemoryOwnerType::USER->value,
            'owner_id' => $thread->user_id,
            'kind' => 'preference',
            'content' => 'Enjoys concise summaries.',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $freshThread = $thread->fresh();
        $this->assertInstanceOf(AiThread::class, $freshThread);

        $state = $this->app->make(ThreadStateService::class)->forThread($freshThread);
        $context = new PromptVariableContext($state, $prompt, $assistant);

        $rendered = $this->app->make(PromptVariableService::class)
            ->apply($prompt->system_prompt, $context);

        $this->assertSame(
            "Memories -> Contextual memories:\n- (fact) User lives in Toronto.\n- (preference) Enjoys concise summaries.",
            $rendered
        );

        Carbon::setTestNow();
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../../database/migrations';
    }

    private function resetVariableServices(): void
    {
        $this->app->forgetInstance(PromptVariableRegistry::class);
        $this->app->forgetInstance(PromptVariableService::class);
    }
}
