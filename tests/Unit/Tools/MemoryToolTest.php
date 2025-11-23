<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Tools;

use Atlas\Nexus\Integrations\Prism\Tools\MemoryTool;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Models\AiMemoryService;
use Atlas\Nexus\Support\Chat\ThreadState;
use Atlas\Nexus\Tests\TestCase;

class MemoryToolTest extends TestCase
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

    public function test_it_adds_memory(): void
    {
        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        $tool = new MemoryTool($this->app->make(AiMemoryService::class));
        $state = $this->threadState($thread);
        $tool->setThreadState($state);

        $response = $tool->handle([
            'action' => 'add',
            'type' => 'fact',
            'content' => 'User prefers SMS alerts.',
        ]);

        $this->assertSame('Memory added.', $response->message());
        $this->assertDatabaseHas('ai_memories', [
            'kind' => 'fact',
            'content' => 'User prefers SMS alerts.',
        ]);
    }

    public function test_it_fetches_memories(): void
    {
        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        $memory = $this->app->make(AiMemoryService::class)->saveForThread(
            $this->assistant('general-assistant'),
            $thread,
            'fact',
            'Favorite color is blue'
        );

        $tool = new MemoryTool($this->app->make(AiMemoryService::class));
        $tool->setThreadState($this->threadState($thread));

        $response = $tool->handle([
            'action' => 'fetch',
        ]);

        $this->assertStringContainsString('Favorite color is blue', $response->message());
        $memories = $response->meta()['memories'] ?? [];
        $this->assertSame($memory->id, $memories[0]['id'] ?? null);
    }

    private function threadState(AiThread $thread): ThreadState
    {
        return $this->app->make(\Atlas\Nexus\Services\Threads\ThreadStateService::class)->forThread($thread);
    }

    private function assistant(string $key): \Atlas\Nexus\Support\Assistants\ResolvedAssistant
    {
        return $this->app->make(\Atlas\Nexus\Services\Assistants\AssistantRegistry::class)->require($key);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
