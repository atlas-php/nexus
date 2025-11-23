<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Enums\AiMessageStatus;
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
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Class ThreadStateService
 *
 * Builds a representation of a thread's state, including prompt, eligible messages, memories, tool access, and rendered system prompt.
 */
class ThreadStateService
{
    private const THREAD_MANAGER_KEY = 'thread-manager';

    public function __construct(
        private readonly AiMessageService $messageService,
        private readonly AiMemoryService $memoryService,
        private readonly ProviderToolRegistry $providerToolRegistry,
        private readonly ToolRegistry $toolRegistry,
        private readonly PromptVariableService $promptVariableService,
        private readonly AssistantRegistry $assistantRegistry
    ) {}

    public function forThread(AiThread $thread): ThreadState
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

        $tools = $this->resolveTools($assistant);
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
    protected function resolveTools(ResolvedAssistant $assistant): Collection
    {
        $toolKeys = $assistant->tools();

        return collect($this->toolRegistry->forKeys($toolKeys));
    }

    /**
     * @return Collection<int, \Atlas\Nexus\Support\Tools\ProviderToolDefinition>
     */
    protected function resolveProviderTools(ResolvedAssistant $assistant): Collection
    {
        $keys = $assistant->providerTools();

        if ($keys === []) {
            return collect();
        }

        $definitions = [];

        foreach ($keys as $key) {
            $options = $assistant->providerToolConfiguration($key);
            $definition = $this->providerToolRegistry->definitionWithOptions($key, $options);

            if ($definition === null) {
                continue;
            }

            $definitions[] = $definition;
        }

        return collect($definitions);
    }

    protected function resolveSystemPrompt(ThreadState $state): ?string
    {
        if ($state->prompt === null) {
            return null;
        }

        $contextThread = $this->threadForPromptVariables($state);
        $context = $contextThread->is($state->thread)
            ? new PromptVariableContext($state)
            : new PromptVariableContext($state, null, null, null, $contextThread);
        $render = $this->promptVariableService->renderWithVariables($state->prompt, $context);

        return $render['rendered_prompt'];
    }

    protected function threadForPromptVariables(ThreadState $state): AiThread
    {
        if ($state->assistant->key() !== self::THREAD_MANAGER_KEY) {
            return $state->thread;
        }

        $parent = $state->thread->getRelationValue('parentThread');

        if ($parent instanceof AiThread) {
            return $parent;
        }

        if ($state->thread->parent_thread_id === null) {
            return $state->thread;
        }

        $loadedParent = $state->thread->parentThread()->first();

        return $loadedParent instanceof AiThread ? $loadedParent : $state->thread;
    }
}
