<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services;

use Atlas\Nexus\Enums\AiMemoryOwnerType;
use Atlas\Nexus\Enums\AiMessageContentType;
use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiThreadStatus;
use Atlas\Nexus\Enums\AiToolRunStatus;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiPrompt;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Models\AiToolRun;
use Atlas\Nexus\Services\Models\AiAssistantService;
use Atlas\Nexus\Services\Models\AiMemoryService;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Models\AiPromptService;
use Atlas\Nexus\Services\Models\AiThreadService;
use Atlas\Nexus\Services\Models\AiToolRunService;
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

        /** @var array<string, mixed> $assistantData */
        $assistantData = AiAssistant::factory()->raw(['slug' => 'svc-assistant']);
        $assistant = $assistantService->create($assistantData);

        $assistantService->update($assistant, ['name' => 'Updated Assistant']);

        $assistantService->addTool($assistant, 'memory');
        $assistantService->addTool($assistant, 'search');

        $this->assertSame(['memory', 'search'], $assistant->refresh()->tools);

        $assistantService->removeTool($assistant, 'memory');
        $this->assertSame(['search'], $assistant->refresh()->tools);

        $assistantService->syncTools($assistant, ['alpha', 'beta', 'beta', '']);
        $this->assertSame(['alpha', 'beta'], $assistant->refresh()->tools);

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
        $toolRunService = $this->app->make(AiToolRunService::class);

        /** @var array<string, mixed> $assistantData */
        $assistantData = AiAssistant::factory()->raw(['slug' => 'svc-assistant-thread']);
        $assistant = $assistantService->create($assistantData);

        /** @var array<string, mixed> $promptData */
        $promptData = AiPrompt::factory()->raw([
            'version' => 1,
        ]);
        $prompt = $promptService->create($promptData);

        /** @var array<string, mixed> $threadData */
        $threadData = AiThread::factory()->raw([
            'assistant_id' => $assistant->id,
            'prompt_id' => $prompt->id,
            'last_message_at' => Carbon::now(),
            'status' => AiThreadStatus::OPEN->value,
        ]);
        $thread = $threadService->create($threadData);

        /** @var array<string, mixed> $messageData */
        $messageData = AiMessage::factory()->raw([
            'thread_id' => $thread->id,
            'user_id' => $thread->user_id,
            'sequence' => 1,
            'content_type' => AiMessageContentType::TEXT->value,
            'role' => AiMessageRole::USER->value,
        ]);
        $message = $messageService->create($messageData);

        /** @var array<string, mixed> $runData */
        $runData = AiToolRun::factory()->raw([
            'tool_key' => 'svc-tool-status',
            'thread_id' => $thread->id,
            'assistant_message_id' => $message->id,
            'status' => AiToolRunStatus::QUEUED->value,
            'started_at' => null,
            'finished_at' => null,
        ]);
        $run = $toolRunService->create($runData);

        /** @var array<string, mixed> $memoryData */
        $memoryData = AiMemory::factory()->raw([
            'assistant_id' => $assistant->id,
            'thread_id' => $thread->id,
            'source_message_id' => $message->id,
        ]);
        $memory = $memoryService->create($memoryData);

        $this->assertTrue($thread->status === AiThreadStatus::OPEN);
        $this->assertSame($prompt->id, $thread->prompt_id);
        $this->assertSame($thread->id, $message->thread_id);
        $this->assertSame($assistant->id, $memory->assistant_id);
        $this->assertSame($message->id, $memory->source_message_id);

        $runningRun = $toolRunService->markStatus($run, AiToolRunStatus::RUNNING);
        $this->assertTrue($runningRun->status === AiToolRunStatus::RUNNING);
        $this->assertNotNull($runningRun->started_at);
        $this->assertNull($runningRun->finished_at);

        $finishedRun = $toolRunService->markStatus($runningRun, AiToolRunStatus::SUCCEEDED);
        $this->assertTrue($finishedRun->status === AiToolRunStatus::SUCCEEDED);
        $this->assertNotNull($finishedRun->finished_at);

        $updatedThread = $threadService->update($thread, ['status' => AiThreadStatus::CLOSED->value]);
        $this->assertTrue($updatedThread->status === AiThreadStatus::CLOSED);

        $updatedMemory = $memoryService->update($memory, ['kind' => 'summary']);
        $this->assertSame('summary', $updatedMemory->kind);

        $this->assertTrue($threadService->delete($thread));
        $this->assertModelMissing($thread);
        $this->assertSoftDeleted($memory);
        $this->assertSoftDeleted($message);
        $this->assertModelMissing($runningRun);
    }

    public function test_tool_run_and_mapping_services_update_status_and_cleanup(): void
    {
        $assistantService = $this->app->make(AiAssistantService::class);
        $toolRunService = $this->app->make(AiToolRunService::class);
        $threadService = $this->app->make(AiThreadService::class);
        $messageService = $this->app->make(AiMessageService::class);

        /** @var array<string, mixed> $assistantData */
        $assistantData = AiAssistant::factory()->raw(['slug' => 'svc-assistant-tool-run']);
        $assistant = $assistantService->create($assistantData);

        /** @var array<string, mixed> $threadData */
        $threadData = AiThread::factory()->raw([
            'assistant_id' => $assistant->id,
            'user_id' => 99,
            'status' => AiThreadStatus::OPEN->value,
        ]);
        $thread = $threadService->create($threadData);

        /** @var array<string, mixed> $messageData */
        $messageData = AiMessage::factory()->raw([
            'thread_id' => $thread->id,
            'role' => AiMessageRole::ASSISTANT->value,
            'sequence' => 1,
        ]);
        $message = $messageService->create($messageData);

        /** @var array<string, mixed> $runData */
        $runData = AiToolRun::factory()->raw([
            'tool_key' => 'svc-tool-run',
            'thread_id' => $thread->id,
            'assistant_message_id' => $message->id,
            'status' => AiToolRunStatus::QUEUED->value,
        ]);
        $run = $toolRunService->create($runData);

        $this->assertTrue($run->status === AiToolRunStatus::QUEUED);

        $updatedRun = $toolRunService->markStatus($run, AiToolRunStatus::RUNNING);
        $this->assertTrue($updatedRun->status === AiToolRunStatus::RUNNING);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }

    public function test_group_id_propagates_to_related_records(): void
    {
        $assistantService = $this->app->make(AiAssistantService::class);
        $threadService = $this->app->make(AiThreadService::class);
        $messageService = $this->app->make(AiMessageService::class);
        $memoryService = $this->app->make(AiMemoryService::class);
        $toolRunService = $this->app->make(AiToolRunService::class);

        /** @var array<string, mixed> $assistantData */
        $assistantData = AiAssistant::factory()->raw(['slug' => 'grouped']);
        $assistant = $assistantService->create($assistantData);

        /** @var array<string, mixed> $threadData */
        $threadData = AiThread::factory()->raw([
            'assistant_id' => $assistant->id,
            'user_id' => 11,
            'status' => AiThreadStatus::OPEN->value,
            'group_id' => 99,
        ]);
        $thread = $threadService->create($threadData);

        $message = $messageService->create([
            'thread_id' => $thread->id,
            'user_id' => $thread->user_id,
            'role' => AiMessageRole::USER->value,
            'content' => 'hello',
            'content_type' => AiMessageContentType::TEXT->value,
            'sequence' => 1,
            'status' => \Atlas\Nexus\Enums\AiMessageStatus::COMPLETED->value,
        ]);

        $this->assertSame(99, $message->group_id);

        $memory = $memoryService->saveForThread(
            $assistant,
            $thread,
            'fact',
            'Remember this.',
            AiMemoryOwnerType::USER
        );

        $this->assertSame(99, $memory->group_id);

        $assistantMessage = $messageService->create([
            'thread_id' => $thread->id,
            'user_id' => null,
            'role' => AiMessageRole::ASSISTANT->value,
            'content' => 'response',
            'content_type' => AiMessageContentType::TEXT->value,
            'sequence' => 2,
            'status' => \Atlas\Nexus\Enums\AiMessageStatus::PROCESSING->value,
        ]);

        $toolRun = $toolRunService->create([
            'tool_key' => 'grouped-tool',
            'thread_id' => $thread->id,
            'assistant_message_id' => $assistantMessage->id,
            'call_index' => 0,
            'input_args' => [],
            'status' => AiToolRunStatus::RUNNING->value,
        ]);

        $this->assertSame(99, $toolRun->group_id);
    }
}
