<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services\Prompts;

use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Prompts\PromptVariableService;
use Atlas\Nexus\Services\Threads\ThreadStateService;
use Atlas\Nexus\Tests\Fixtures\Assistants\PrimaryAssistantDefinition;
use Atlas\Nexus\Tests\TestCase;

class PromptVariableServiceTest extends TestCase
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

    public function test_it_applies_thread_variables(): void
    {
        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
            'title' => 'Sample Thread',
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'content' => 'Hello world',
        ]);

        $state = $this->app->make(ThreadStateService::class)->forThread($thread);
        $service = $this->app->make(PromptVariableService::class);

        $rendered = $service->apply(
            'Thread {THREAD.ID}: {THREAD.TITLE}',
            new \Atlas\Nexus\Support\Prompts\PromptVariableContext($state)
        );

        $this->assertStringContainsString((string) $thread->id, $rendered);
        $this->assertStringContainsString('Sample Thread', $rendered);
    }

    public function test_it_uses_assistant_override_prompt(): void
    {
        $prompt = 'Hello {CUSTOM.VALUE}';

        PrimaryAssistantDefinition::updateConfig([
            'system_prompt' => 'Hello {CUSTOM.VALUE}',
        ]);

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        $state = $this->app->make(ThreadStateService::class)->forThread($thread);
        $service = $this->app->make(PromptVariableService::class);
        $context = new \Atlas\Nexus\Support\Prompts\PromptVariableContext($state);

        $rendered = $service->apply($prompt, $context, ['CUSTOM.VALUE' => 'World']);

        $this->assertSame('Hello World', $rendered);

        PrimaryAssistantDefinition::resetConfig();
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../../database/migrations';
    }
}
