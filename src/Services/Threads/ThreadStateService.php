<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiPrompt;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Models\AiMemoryService;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Support\Chat\ThreadState;
use Illuminate\Database\Eloquent\Builder;
use RuntimeException;

/**
 * Class ThreadStateService
 *
 * Builds a snapshot of a thread's state, including prompt, eligible messages, memories, and available tools.
 */
class ThreadStateService
{
    public function __construct(
        private readonly AiMessageService $messageService,
        private readonly AiMemoryService $memoryService
    ) {}

    public function forThread(AiThread $thread): ThreadState
    {
        $thread->loadMissing(['assistant', 'prompt', 'assistant.currentPrompt', 'assistant.tools']);

        $assistant = $thread->assistant;

        if ($assistant === null) {
            throw new RuntimeException('Thread is missing an associated assistant.');
        }

        $prompt = $this->resolvePrompt($thread, $assistant);
        $messages = $this->messageService->query()
            ->where('thread_id', $thread->id)
            ->where('status', AiMessageStatus::COMPLETED->value)
            ->orderBy('sequence')
            ->get();

        $memories = $this->memoryService->query()
            ->where(function (Builder $query) use ($assistant): void {
                $query->whereNull('assistant_id')
                    ->orWhere('assistant_id', $assistant->id);
            })
            ->where(function (Builder $query) use ($thread): void {
                $query->whereNull('thread_id')
                    ->orWhere('thread_id', $thread->id);
            })
            ->orderBy('id')
            ->get();

        $tools = $assistant->tools()->where('is_active', true)->get();

        return new ThreadState(
            $thread,
            $assistant,
            $prompt,
            $messages,
            $memories,
            $tools
        );
    }

    protected function resolvePrompt(AiThread $thread, AiAssistant $assistant): ?AiPrompt
    {
        return $thread->prompt ?? $assistant->currentPrompt;
    }
}
