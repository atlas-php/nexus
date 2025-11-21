<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services;

use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiAssistantTool;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiPrompt;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Models\AiTool;
use Atlas\Nexus\Models\AiToolRun;
use Atlas\Nexus\Services\AiAssistantService;
use Atlas\Nexus\Services\AiAssistantToolService;
use Atlas\Nexus\Services\AiMemoryService;
use Atlas\Nexus\Services\AiMessageService;
use Atlas\Nexus\Services\AiPromptService;
use Atlas\Nexus\Services\AiThreadService;
use Atlas\Nexus\Services\AiToolRunService;
use Atlas\Nexus\Services\AiToolService;
use Atlas\Nexus\Tests\TestCase;
use Illuminate\Support\Carbon;

/**
 * Class AiModelServiceTest
 *
 * Ensures Nexus model services expose CRUD helpers and coordinate pivot interactions safely.
 * PRD Reference: Atlas Nexus Overview — Service usage for model CRUD flows.
 */
class AiModelServiceTest extends TestCase
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

    public function test_assistant_service_manages_tools_and_soft_deletes(): void
    {
        $assistantService = $this->app->make(AiAssistantService::class);
        $toolService = $this->app->make(AiToolService::class);

        /** @var array<string, mixed> $assistantData */
        $assistantData = AiAssistant::factory()->raw(['slug' => 'svc-assistant']);
        $assistant = $assistantService->create($assistantData);

        $assistantService->update($assistant, ['name' => 'Updated Assistant']);

        /** @var array<string, mixed> $toolData */
        $toolData = AiTool::factory()->raw(['slug' => 'svc-tool']);
        $tool = $toolService->create($toolData);

        $mapping = $assistantService->attachTool($assistant, $tool, ['mode' => 'sync']);

        $this->assertInstanceOf(AiAssistantTool::class, $mapping);
        $this->assertTrue($assistant->tools()->whereKey($tool->id)->exists());

        $assistantService->detachTool($assistant, $tool);
        $this->assertFalse($assistant->tools()->whereKey($tool->id)->exists());

        $this->assertTrue($assistantService->delete($assistant));
        $this->assertSoftDeleted($assistant);
    }

    public function test_prompt_thread_message_and_memory_services_coordinate_crud(): void
    {
        $assistantService = $this->app->make(AiAssistantService::class);
        $promptService = $this->app->make(AiPromptService::class);
        $threadService = $this->app->make(AiThreadService::class);
        $messageService = $this->app->make(AiMessageService::class);
        $memoryService = $this->app->make(AiMemoryService::class);

        /** @var array<string, mixed> $assistantData */
        $assistantData = AiAssistant::factory()->raw(['slug' => 'svc-assistant-thread']);
        $assistant = $assistantService->create($assistantData);

        /** @var array<string, mixed> $promptData */
        $promptData = AiPrompt::factory()->raw([
            'assistant_id' => $assistant->id,
            'version' => 1,
        ]);
        $prompt = $promptService->create($promptData);

        /** @var array<string, mixed> $threadData */
        $threadData = AiThread::factory()->raw([
            'assistant_id' => $assistant->id,
            'prompt_id' => $prompt->id,
            'last_message_at' => Carbon::now(),
            'status' => 'open',
        ]);
        $thread = $threadService->create($threadData);

        /** @var array<string, mixed> $messageData */
        $messageData = AiMessage::factory()->raw([
            'thread_id' => $thread->id,
            'user_id' => $thread->user_id,
            'sequence' => 1,
            'content_type' => 'text',
            'role' => 'user',
        ]);
        $message = $messageService->create($messageData);

        /** @var array<string, mixed> $memoryData */
        $memoryData = AiMemory::factory()->raw([
            'assistant_id' => $assistant->id,
            'thread_id' => $thread->id,
            'source_message_id' => $message->id,
        ]);
        $memory = $memoryService->create($memoryData);

        $this->assertSame('open', $thread->status);
        $this->assertSame($prompt->id, $thread->prompt_id);
        $this->assertSame($thread->id, $message->thread_id);
        $this->assertSame($assistant->id, $memory->assistant_id);
        $this->assertSame($message->id, $memory->source_message_id);

        $updatedThread = $threadService->update($thread, ['status' => 'closed']);
        $this->assertSame('closed', $updatedThread->status);

        $updatedMemory = $memoryService->update($memory, ['kind' => 'summary']);
        $this->assertSame('summary', $updatedMemory->kind);

        $this->assertTrue($memoryService->delete($memory));
        $this->assertModelMissing($memory);
    }

    public function test_tool_run_and_mapping_services_update_status_and_cleanup(): void
    {
        $assistantService = $this->app->make(AiAssistantService::class);
        $toolService = $this->app->make(AiToolService::class);
        $assistantToolService = $this->app->make(AiAssistantToolService::class);
        $toolRunService = $this->app->make(AiToolRunService::class);
        $threadService = $this->app->make(AiThreadService::class);
        $messageService = $this->app->make(AiMessageService::class);

        /** @var array<string, mixed> $assistantData */
        $assistantData = AiAssistant::factory()->raw(['slug' => 'svc-assistant-tool-run']);
        $assistant = $assistantService->create($assistantData);

        /** @var array<string, mixed> $toolData */
        $toolData = AiTool::factory()->raw(['slug' => 'svc-tool-run']);
        $tool = $toolService->create($toolData);
        /** @var array<string, mixed> $assistantToolData */
        $assistantToolData = [
            'assistant_id' => $assistant->id,
            'tool_id' => $tool->id,
            'config' => ['mode' => 'async'],
        ];
        $assistantTool = $assistantToolService->create($assistantToolData);

        /** @var array<string, mixed> $threadData */
        $threadData = AiThread::factory()->raw([
            'assistant_id' => $assistant->id,
            'user_id' => 99,
            'status' => 'open',
        ]);
        $thread = $threadService->create($threadData);

        /** @var array<string, mixed> $messageData */
        $messageData = AiMessage::factory()->raw([
            'thread_id' => $thread->id,
            'role' => 'assistant',
            'sequence' => 1,
        ]);
        $message = $messageService->create($messageData);

        /** @var array<string, mixed> $runData */
        $runData = AiToolRun::factory()->raw([
            'tool_id' => $tool->id,
            'thread_id' => $thread->id,
            'assistant_message_id' => $message->id,
            'status' => 'queued',
        ]);
        $run = $toolRunService->create($runData);

        $this->assertSame('queued', $run->status);

        $updatedRun = $toolRunService->markStatus($run, 'running');
        $this->assertSame('running', $updatedRun->status);

        $this->assertTrue($assistantToolService->delete($assistantTool));
        $this->assertModelMissing($assistantTool);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
