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
 * Ensures the built-in memory tool can add, update, fetch, and remove scoped memories for a thread.
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

    public function test_memory_tool_handles_add_fetch_update_and_delete(): void
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
            collect(),
            null,
            null,
            collect()
        ));

        $addResponse = $tool->handle([
            'action' => 'add',
            'type' => 'preference',
            'content' => 'User prefers email summaries.',
        ]);

        $this->assertSame('Memory added.', $addResponse->message());
        $memoryId = $addResponse->meta()['memory_ids'][0];

        $memory = AiMemory::find($memoryId);
        $this->assertInstanceOf(AiMemory::class, $memory);
        $this->assertNull($memory->thread_id);
        $this->assertSame($thread->user_id, $memory->owner_id);

        $fetchResponse = $tool->handle([
            'action' => 'fetch',
            'memory_ids' => [$memoryId],
        ]);

        $this->assertStringContainsString('Memories found', $fetchResponse->message());

        /** @var array<int, array<string, mixed>> $fetched */
        $fetched = $fetchResponse->meta()['memories'];
        $this->assertNotEmpty($fetched);
        $this->assertTrue(collect($fetched)->contains(fn ($memory) => $memory['id'] === $memoryId));
        $this->assertSame($assistant->id, $fetched[0]['assistant_id']);
        $this->assertSame($thread->user_id, $fetched[0]['user_id']);

        $updateResponse = $tool->handle([
            'action' => 'update',
            'memory_ids' => [$memoryId],
            'content' => 'User prefers SMS alerts.',
            'type' => 'constraint',
        ]);

        $this->assertSame('Memory updated.', $updateResponse->message());
        $memory->refresh();
        $this->assertSame('constraint', $memory->kind);
        $this->assertSame('User prefers SMS alerts.', $memory->content);

        $deleteResponse = $tool->handle([
            'action' => 'delete',
            'memory_ids' => [$memoryId],
        ]);

        $this->assertSame('Memory removed.', $deleteResponse->message());
        $this->assertSoftDeleted($memory);
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
            new Collection,
            null,
            null,
            new Collection
        ));

        $response = $tool->handle([
            'action' => 'delete',
            'memory_ids' => [404],
        ]);

        $this->assertSame('Some memories could not be removed.', $response->message());
        $this->assertArrayHasKey(404, $response->meta()['errors']);
    }

    public function test_memory_tool_can_delete_multiple_or_suggest_ids(): void
    {
        $assistant = AiAssistant::factory()->create(['slug' => 'memory-tool-multi']);
        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'user_id' => 111,
        ]);

        $tool = $this->app->make(MemoryTool::class);
        $tool->setThreadState(new ThreadState(
            $thread,
            $assistant,
            null,
            new Collection,
            new Collection,
            new Collection,
            null,
            null,
            new Collection
        ));

        $memA = AiMemory::factory()->create([
            'assistant_id' => $assistant->id,
            'thread_id' => null,
            'owner_type' => 'user',
            'owner_id' => $thread->user_id,
        ]);

        $memB = AiMemory::factory()->create([
            'assistant_id' => $assistant->id,
            'thread_id' => null,
            'owner_type' => 'user',
            'owner_id' => $thread->user_id,
        ]);

        $orderedFetch = $tool->handle([
            'action' => 'fetch',
        ]);

        $orderedMemories = $orderedFetch->meta()['memories'];
        $this->assertSame($memB->id, $orderedMemories[0]['id']);

        $filteredFetch = $tool->handle([
            'action' => 'fetch',
            'memory_ids' => [$memA->id],
        ]);

        $this->assertCount(1, $filteredFetch->meta()['memories']);
        $this->assertSame($memA->id, $filteredFetch->meta()['memories'][0]['id']);

        $suggestion = $tool->handle([
            'action' => 'delete',
        ]);

        $this->assertTrue($suggestion->meta()['error']);
        $this->assertNotEmpty($suggestion->meta()['available_memories']);

        $response = $tool->handle([
            'action' => 'delete',
            'memory_ids' => [$memA->id, $memB->id],
        ]);

        $this->assertSame('Memory removed.', $response->message());
        $this->assertSame([], $response->meta()['errors']);
        $this->assertTrue(in_array($memA->id, $response->meta()['removed_ids'], true));
        $this->assertTrue(in_array($memB->id, $response->meta()['removed_ids'], true));
        $this->assertSoftDeleted($memA);
        $this->assertSoftDeleted($memB);
    }

    public function test_memory_tool_only_deletes_memories_for_current_context(): void
    {
        $assistant = AiAssistant::factory()->create(['slug' => 'memory-tool-guard']);
        $otherAssistant = AiAssistant::factory()->create(['slug' => 'memory-tool-guard-foreign']);
        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'user_id' => 4444,
        ]);

        $tool = $this->app->make(MemoryTool::class);
        $tool->setThreadState(new ThreadState(
            $thread,
            $assistant,
            null,
            new Collection,
            new Collection,
            new Collection,
            null,
            null,
            new Collection
        ));

        $foreignUserMemory = AiMemory::factory()->create([
            'assistant_id' => $assistant->id,
            'owner_type' => 'user',
            'owner_id' => 12345,
            'thread_id' => null,
        ]);

        $foreignAssistantMemory = AiMemory::factory()->create([
            'assistant_id' => $otherAssistant->id,
            'owner_type' => 'user',
            'owner_id' => $thread->user_id,
            'thread_id' => null,
        ]);

        $response = $tool->handle([
            'action' => 'delete',
            'memory_ids' => [$foreignUserMemory->id, $foreignAssistantMemory->id],
        ]);

        $this->assertSame('Some memories could not be removed.', $response->message());
        $this->assertSame([], $response->meta()['removed_ids']);
        $this->assertArrayHasKey($foreignUserMemory->id, $response->meta()['errors']);
        $this->assertArrayHasKey($foreignAssistantMemory->id, $response->meta()['errors']);
        $refreshedForeignUserMemory = $foreignUserMemory->fresh();
        $refreshedForeignAssistantMemory = $foreignAssistantMemory->fresh();

        $this->assertNotNull($refreshedForeignUserMemory);
        $this->assertNotNull($refreshedForeignAssistantMemory);
        $this->assertNull($refreshedForeignUserMemory->deleted_at);
        $this->assertNull($refreshedForeignAssistantMemory->deleted_at);
    }

    public function test_memory_tool_validates_add_and_update_requirements(): void
    {
        $assistant = AiAssistant::factory()->create(['slug' => 'memory-tool-validation']);
        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'user_id' => 222,
        ]);

        $tool = $this->app->make(MemoryTool::class);
        $tool->setThreadState(new ThreadState(
            $thread,
            $assistant,
            null,
            collect(),
            collect(),
            collect(),
            null,
            null,
            collect()
        ));

        $addResponse = $tool->handle([
            'action' => 'add',
        ]);

        $this->assertTrue($addResponse->meta()['error']);

        $updateResponse = $tool->handle([
            'action' => 'update',
            'content' => 'Missing id',
        ]);

        $this->assertTrue($updateResponse->meta()['error']);
        $this->assertStringContainsString('memory_ids is required', $updateResponse->message());
    }

    public function test_memory_tool_rejects_unknown_types(): void
    {
        $assistant = AiAssistant::factory()->create(['slug' => 'memory-tool-type']);
        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'user_id' => 333,
        ]);

        $tool = $this->app->make(MemoryTool::class);
        $tool->setThreadState(new ThreadState(
            $thread,
            $assistant,
            null,
            collect(),
            collect(),
            collect(),
            null,
            null,
            collect()
        ));

        $response = $tool->handle([
            'action' => 'add',
            'type' => 'mystery',
            'content' => 'Unsupported type.',
        ]);

        $this->assertTrue($response->meta()['error']);
        $this->assertStringContainsString('Memory type must be one of', $response->message());
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
