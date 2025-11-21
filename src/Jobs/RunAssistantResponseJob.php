<?php

declare(strict_types=1);

namespace Atlas\Nexus\Jobs;

use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Threads\AssistantResponseService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

/**
 * Class RunAssistantResponseJob
 *
 * Delegates assistant replies to the shared response service, honoring configuration for queue selection or inline execution.
 */
class RunAssistantResponseJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public int $timeout = 1_200;

    public function __construct(public int $assistantMessageId)
    {
        $queue = $this->resolveQueue();

        if ($queue !== null) {
            $this->onQueue($queue);
        }
    }

    public function handle(AssistantResponseService $assistantResponseService): void
    {
        $assistantResponseService->handle($this->assistantMessageId);
    }

    public function failed(Throwable $exception): void
    {
        $messageService = app(AiMessageService::class);
        $message = $messageService->find($this->assistantMessageId);

        if ($message !== null) {
            $messageService->markStatus($message, AiMessageStatus::FAILED, $exception->getMessage());
        }
    }

    protected function resolveQueue(): ?string
    {
        $queue = config('atlas-nexus.responses.queue');

        return is_string($queue) && $queue !== '' ? $queue : null;
    }
}
