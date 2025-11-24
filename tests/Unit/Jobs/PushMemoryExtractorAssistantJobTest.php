<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Jobs;

use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Jobs\PushMemoryExtractorAssistantJob;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Models\AiThreadService;
use Atlas\Nexus\Services\Threads\ThreadMemoryExtractionService;
use Atlas\Nexus\Tests\TestCase;
use Mockery;

class PushMemoryExtractorAssistantJobTest extends TestCase
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

    public function test_it_runs_extraction_and_clears_flag(): void
    {
        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
            'metadata' => ['memory_job_pending' => true],
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::USER->value,
            'sequence' => 1,
            'status' => AiMessageStatus::COMPLETED->value,
            'is_memory_checked' => false,
        ]);

        AiMessage::factory()->create([
            'thread_id' => $thread->id,
            'assistant_key' => $thread->assistant_key,
            'role' => AiMessageRole::ASSISTANT->value,
            'sequence' => 2,
            'status' => AiMessageStatus::COMPLETED->value,
            'is_memory_checked' => false,
        ]);

        $extraction = Mockery::mock(ThreadMemoryExtractionService::class);
        $extraction
            ->shouldReceive('extractFromMessages')
            ->once()
            ->with(
                Mockery::on(fn (AiThread $argument): bool => $argument->is($thread)),
                Mockery::type(\Illuminate\Support\Collection::class)
            );

        $this->app->instance(ThreadMemoryExtractionService::class, $extraction);

        $job = new PushMemoryExtractorAssistantJob($thread->id);
        $job->handle(
            $this->app->make(AiThreadService::class),
            $this->app->make(AiMessageService::class),
            $extraction
        );

        $metadata = $thread->fresh()->metadata ?? [];

        $this->assertIsArray($metadata);
        $this->assertArrayNotHasKey('memory_job_pending', $metadata);
    }

    public function test_it_clears_flag_when_no_messages(): void
    {
        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
            'metadata' => ['memory_job_pending' => true],
        ]);

        $extraction = Mockery::mock(ThreadMemoryExtractionService::class);
        $extraction->shouldNotReceive('extractFromMessages');

        $this->app->instance(ThreadMemoryExtractionService::class, $extraction);

        $job = new PushMemoryExtractorAssistantJob($thread->id);
        $job->handle(
            $this->app->make(AiThreadService::class),
            $this->app->make(AiMessageService::class),
            $extraction
        );

        $metadata = $thread->fresh()->metadata ?? [];

        $this->assertIsArray($metadata);
        $this->assertArrayNotHasKey('memory_job_pending', $metadata);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
