<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Integrations\Prism\Tools\MemoryTool;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Assistants\AssistantRegistry;
use Atlas\Nexus\Services\Models\AiMemoryService;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Prompts\PromptVariableService;
use Atlas\Nexus\Services\Tools\ProviderToolRegistry;
use Atlas\Nexus\Services\Tools\ToolRegistry;
use Atlas\Nexus\Support\Assistants\ResolvedAssistant;
use Atlas\Nexus\Support\Chat\ThreadState;
use Atlas\Nexus\Support\Prompts\PromptVariableContext;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Class ThreadStateService
 *
 * Builds a representation of a thread's state, including prompt, eligible messages, memories, tool access, and rendered system prompt.
 */
class ThreadStateService
{
    protected bool $includeMemoryTool;

    public function __construct(
        private readonly AiMessageService $messageService,
        private readonly AiMemoryService $memoryService,
        private readonly ProviderToolRegistry $providerToolRegistry,
        private readonly ToolRegistry $toolRegistry,
        private readonly PromptVariableService $promptVariableService,
        private readonly AssistantRegistry $assistantRegistry,
        ConfigRepository $config
    ) {
        $this->includeMemoryTool = (bool) $config->get('atlas-nexus.tools.memory.enabled', true);
    }

    public function forThread(AiThread $thread, ?bool $includeMemoryTool = null): ThreadState
    {
        $assistantKey = $thread->assistant_key;

        if ($assistantKey === '') {
            throw new RuntimeException('Thread is missing an assistant key.');
        }

        $assistant = $this->assistantRegistry->require($assistantKey);
        $prompt = $assistant->systemPrompt();
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
        $providerTools = $this->resolveProviderTools($assistant);

        $state = new ThreadState(
            $thread,
            $assistant,
            $prompt,
            $messages,
            $memories,
            $tools,
            null,
            $providerTools
        );

        $systemPrompt = $this->resolveSystemPrompt($state);

        return new ThreadState(
            $thread,
            $assistant,
            $prompt,
            $messages,
            $memories,
            $tools,
            $systemPrompt,
            $providerTools
        );
    }

    /**
     * @return Collection<int, \Atlas\Nexus\Support\Tools\ToolDefinition>
     */
    protected function resolveTools(ResolvedAssistant $assistant, bool $includeMemoryTool): Collection
    {
        $toolKeys = $assistant->tools();

        if (! $includeMemoryTool) {
            $toolKeys = array_values(array_filter(
                $toolKeys,
                static fn (string $key): bool => $key !== MemoryTool::KEY
            ));
        } elseif (! in_array(MemoryTool::KEY, $toolKeys, true)) {
            $toolKeys[] = MemoryTool::KEY;
        }

        return collect($this->toolRegistry->forKeys($toolKeys));
    }

    /**
     * @return Collection<int, \Atlas\Nexus\Support\Tools\ProviderToolDefinition>
     */
    protected function resolveProviderTools(ResolvedAssistant $assistant): Collection
    {
        $keys = $assistant->providerTools();

        return collect($this->providerToolRegistry->forKeys($keys));
    }

    protected function resolveSystemPrompt(ThreadState $state): ?string
    {
        if ($state->prompt === null) {
            return null;
        }

        $context = new PromptVariableContext($state);
        $render = $this->promptVariableService->renderWithVariables($state->prompt, $context);

        return $render['rendered_prompt'];
    }
}
