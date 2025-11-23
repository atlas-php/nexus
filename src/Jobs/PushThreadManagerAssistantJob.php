<?php

declare(strict_types=1);

namespace Atlas\Nexus\Jobs;

use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Services\Assistants\AssistantRegistry;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Models\AiThreadService;
use Atlas\Nexus\Services\Threads\ThreadTitleSummaryService;
use Atlas\Nexus\Support\Chat\ThreadState;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

use function collect;

/**
 * Class PushThreadManagerAssistantJob
 *
 * Dispatches the thread manager assistant to summarize a thread once it has reached the required assistant reply threshold.
 */
class PushThreadManagerAssistantJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const THREAD_MANAGER_KEY = 'thread-manager';

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $threadId)
    {
        $queue = $this->resolveQueue();

        if ($queue !== null) {
            $this->onQueue($queue);
        }
    }

    public function handle(
        AiThreadService $threadService,
        AssistantRegistry $assistantRegistry,
        AiMessageService $messageService,
        ThreadTitleSummaryService $threadTitleSummaryService
    ): void {
        $thread = $threadService->find($this->threadId);

        if ($thread === null) {
            return;
        }

        $assistant = $assistantRegistry->require(self::THREAD_MANAGER_KEY);

        $messages = $messageService->query()
            ->where('thread_id', $thread->id)
            ->where('status', AiMessageStatus::COMPLETED->value)
            ->orderBy('sequence')
            ->get();

        if ($messages->isEmpty()) {
            return;
        }

        $state = new ThreadState(
            $thread,
            $assistant,
            $assistant->systemPrompt(),
            $messages,
            collect(),
            collect(),
            null,
            collect()
        );

        $result = $threadTitleSummaryService->generateAndSave($state, true);

        /** @var AiMessage $lastMessage */
        $lastMessage = $messages->last();

        $threadService->update($result['thread'], [
            'last_summary_message_id' => $lastMessage->getKey(),
        ]);
    }

    protected function resolveQueue(): ?string
    {
        $queue = config('atlas-nexus.queue');

        return is_string($queue) && $queue !== '' ? $queue : null;
    }
}
