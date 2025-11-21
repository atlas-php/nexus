<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Integrations\Prism\Tools\MemoryTool;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiPrompt;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Models\AiMemoryService;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Tools\ToolRegistry;
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
        private readonly ToolRegistry $toolRegistry,
        ConfigRepository $config
    ) {
        $this->includeMemoryTool = (bool) $config->get('atlas-nexus.tools.memory.enabled', true);
    }

    public function forThread(AiThread $thread, ?bool $includeMemoryTool = null): ThreadState
    {
        $thread->loadMissing(['assistant', 'prompt', 'assistant.currentPrompt']);

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
     * @return Collection<int, \Atlas\Nexus\Support\Tools\ToolDefinition>
     */
    protected function resolveTools(AiAssistant $assistant, bool $includeMemoryTool): Collection
    {
        $registeredKeys = array_keys($this->toolRegistry->all());
        $toolKeys = array_values(array_unique(array_merge($registeredKeys, $assistant->tools ?? [])));

        if (! $includeMemoryTool) {
            $toolKeys = array_values(array_filter(
                $toolKeys,
                static fn (string $key): bool => $key !== MemoryTool::KEY
            ));
        }

        return collect($this->toolRegistry->forKeys($toolKeys));
    }
}
