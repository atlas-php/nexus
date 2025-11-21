<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Tools;

use Atlas\Nexus\Integrations\Prism\Tools\MemoryTool;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Support\Chat\ThreadState;
use Atlas\Nexus\Tests\TestCase;
use Illuminate\Support\Collection;
use function collect;

/**
 * Class MemoryToolTest
 *
 * Ensures the built-in memory tool can save, fetch, and remove scoped memories for a thread.
 */
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

    public function test_memory_tool_handles_save_fetch_and_delete(): void
    {
        $assistant = AiAssistant::factory()->create(['slug' => 'memory-tool']);
        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'user_id' => 888,
        ]);

        $tool = $this->app->make(MemoryTool::class);
        $tool->setThreadState(new ThreadState(
            $thread,
            $assistant,
            null,
            collect(),
            collect(),
            collect()
        ));

        $saveResponse = $tool->handle([
            'action' => 'save',
            'kind' => 'preference',
            'content' => 'User prefers email summaries.',
            'thread_specific' => true,
        ]);

        $this->assertSame('Memory saved.', $saveResponse->message());
        $memoryId = $saveResponse->meta()['memory_id'];

        $memory = AiMemory::find($memoryId);
        $this->assertInstanceOf(AiMemory::class, $memory);
        $this->assertSame($thread->id, $memory?->thread_id);
        $this->assertSame($thread->user_id, $memory?->owner_id);

        $fetchResponse = $tool->handle([
            'action' => 'fetch',
        ]);

        $this->assertSame('Memories retrieved.', $fetchResponse->message());

        /** @var array<int, array<string, mixed>> $fetched */
        $fetched = $fetchResponse->meta()['memories'];
        $this->assertNotEmpty($fetched);
        $this->assertTrue(collect($fetched)->contains(fn ($memory) => $memory['id'] === $memoryId));

        $deleteResponse = $tool->handle([
            'action' => 'delete',
            'memory_id' => $memoryId,
        ]);

        $this->assertSame('Memory removed.', $deleteResponse->message());
        $this->assertModelMissing($memory);
    }

    public function test_memory_tool_reports_unavailable_memory_removal(): void
    {
        $assistant = AiAssistant::factory()->create(['slug' => 'memory-tool-missing']);
        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'user_id' => 999,
        ]);

        $tool = $this->app->make(MemoryTool::class);
        $tool->setThreadState(new ThreadState(
            $thread,
            $assistant,
            null,
            new Collection,
            new Collection,
            new Collection
        ));

        $response = $tool->handle([
            'action' => 'delete',
            'memory_id' => 404,
        ]);

        $this->assertSame('Memory not found for this assistant or user.', $response->message());
        $this->assertTrue($response->meta()['error']);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
