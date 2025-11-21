<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiPrompt;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Models\AiMemoryService;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Models\AiToolService;
use Atlas\Nexus\Services\Tools\MemoryToolRegistrar;
use Atlas\Nexus\Services\Tools\ToolRunLogger;
use Atlas\Nexus\Support\Chat\ThreadState;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Class ThreadStateService
 *
 * Builds a snapshot of a thread's state, including prompt, eligible messages, memories, and available tools.
 */
class ThreadStateService
{
    protected bool $includeMemoryTool;

    public function __construct(
        private readonly AiMessageService $messageService,
        private readonly AiMemoryService $memoryService,
        private readonly AiToolService $toolService,
        private readonly MemoryToolRegistrar $memoryToolRegistrar,
        private readonly ToolRunLogger $toolRunLogger,
        ConfigRepository $config
    ) {
        $this->includeMemoryTool = (bool) $config->get('atlas-nexus.tools.memory.enabled', true);
    }

    public function forThread(AiThread $thread, ?bool $includeMemoryTool = null): ThreadState
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

        $memories = $this->memoryService->listForThread($assistant, $thread);

        $tools = $this->resolveTools(
            $assistant,
            $includeMemoryTool ?? $this->includeMemoryTool
        );

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

    /**
     * @return Collection<int, \Atlas\Nexus\Models\AiTool>
     */
    protected function resolveTools(AiAssistant $assistant, bool $includeMemoryTool): Collection
    {
        $memoryTool = $includeMemoryTool
            ? $this->memoryToolRegistrar->ensureRegisteredForAssistant($assistant)
            : null;

        $tools = $assistant->tools()->where('is_active', true)->get();

        if ($memoryTool !== null && $memoryTool->is_active && ! $tools->contains('id', $memoryTool->id)) {
            $tools->push($memoryTool);
        }

        return $tools;
    }
}
