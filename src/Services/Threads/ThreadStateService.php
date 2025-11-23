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
use Atlas\Nexus\Services\Prompts\PromptVariableService;
use Atlas\Nexus\Services\Tools\ProviderToolRegistry;
use Atlas\Nexus\Services\Tools\ToolRegistry;
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
        ConfigRepository $config
    ) {
        $this->includeMemoryTool = (bool) $config->get('atlas-nexus.tools.memory.enabled', true);
    }

    public function forThread(AiThread $thread, ?bool $includeMemoryTool = null): ThreadState
    {
        $thread->loadMissing(['assistant', 'assistant.currentPrompt']);

        $assistant = $thread->assistant;

        if ($assistant === null) {
            throw new RuntimeException('Thread is missing an associated assistant.');
        }

        $prompt = $this->resolvePrompt($assistant);
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
            null,
            $systemPrompt,
            $providerTools
        );
    }

    protected function resolvePrompt(
        AiAssistant $assistant
    ): ?AiPrompt {
        return $assistant->currentPrompt;
    }

    /**
     * @return Collection<int, \Atlas\Nexus\Support\Tools\ToolDefinition>
     */
    protected function resolveTools(AiAssistant $assistant, bool $includeMemoryTool): Collection
    {
        $toolKeys = array_values(array_unique(array_map(
            static fn ($key): string => (string) $key,
            $assistant->tools ?? []
        )));

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
    protected function resolveProviderTools(AiAssistant $assistant): Collection
    {
        $keys = array_values(array_unique(array_map(
            static fn ($key): string => (string) $key,
            $assistant->provider_tools ?? []
        )));

        return collect($this->providerToolRegistry->forKeys($keys));
    }

    protected function resolveSystemPrompt(ThreadState $state): ?string
    {
        if ($state->prompt === null) {
            return null;
        }

        $context = new PromptVariableContext($state, $state->prompt, $state->assistant);
        $render = $this->promptVariableService->renderWithVariables(
            $state->prompt->system_prompt,
            $context
        );

        return $render['rendered_prompt'];
    }
}
