<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services\Prompts;

use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiAssistantPrompt;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Prompts\PromptVariableRegistry;
use Atlas\Nexus\Services\Prompts\PromptVariableService;
use Atlas\Nexus\Services\Threads\ThreadStateService;
use Atlas\Nexus\Support\Prompts\PromptVariableContext;
use Atlas\Nexus\Support\Prompts\Variables\UserPromptVariables;
use Atlas\Nexus\Tests\Fixtures\CustomPromptVariable;
use Atlas\Nexus\Tests\Fixtures\TestUser;
use Atlas\Nexus\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
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
