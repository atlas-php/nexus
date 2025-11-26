<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Assistants\AssistantRegistry;
use Atlas\Nexus\Services\Assistants\ResolvedAssistant;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Models\AiThreadService;
use Atlas\Nexus\Services\Prompts\PromptVariableContext;
use Atlas\Nexus\Services\Prompts\PromptVariableService;
use Atlas\Nexus\Services\Threads\Data\ThreadState;
use Atlas\Nexus\Services\Tools\ProviderToolRegistry;
use Atlas\Nexus\Services\Tools\ToolRegistry;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * Class ThreadStateService
 *
 * Builds a representation of a thread's state, including prompt, eligible messages, memories, tool access, and rendered system prompt.
 */
class ThreadStateService
{
    private const THREAD_SUMMARY_ASSISTANT_KEY = 'thread-manager';

    public function __construct(
        private readonly AiMessageService $messageService,
        private readonly ThreadMemoryService $threadMemoryService,
        private readonly ProviderToolRegistry $providerToolRegistry,
        private readonly ToolRegistry $toolRegistry,
        private readonly PromptVariableService $promptVariableService,
        private readonly AssistantRegistry $assistantRegistry,
        private readonly AiThreadService $threadService
    ) {}

    public function forThread(AiThread $thread): ThreadState
    {
        $assistantKey = $thread->assistant_key;

        if ($assistantKey === '') {
            throw new RuntimeException('Thread is missing an assistant key.');
        }

        $assistant = $this->assistantRegistry->require($assistantKey);
        $prompt = $this->resolvePrompt($thread, $assistant);
        $messages = $this->messageService->query()
            ->where('thread_id', $thread->id)
            ->where('status', AiMessageStatus::COMPLETED->value)
            ->orderBy('sequence')
            ->get();

        $memories = $this->threadMemoryService->memoriesForThread($thread);

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
     * @return Collection<int, \Atlas\Nexus\Services\Tools\ToolDefinition>
     */
    protected function resolveTools(ResolvedAssistant $assistant): Collection
    {
        $toolKeys = $assistant->tools();

        return collect($this->toolRegistry->forKeys($toolKeys));
    }

    /**
     * @return Collection<int, \Atlas\Nexus\Services\Tools\ProviderToolDefinition>
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
        if ($state->assistant->key() !== self::THREAD_SUMMARY_ASSISTANT_KEY) {
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

    protected function resolvePrompt(AiThread $thread, ResolvedAssistant $assistant): ?string
    {
        $prompt = $this->normalizePrompt($assistant->systemPrompt());

        if (! $this->promptSnapshotsEnabled()) {
            return $prompt;
        }

        $snapshot = $this->normalizePrompt($thread->prompt_snapshot ?? null);

        if ($snapshot !== null) {
            return $snapshot;
        }

        if ($prompt === null) {
            return null;
        }

        $this->capturePromptSnapshot($thread, $prompt);

        return $prompt;
    }

    protected function capturePromptSnapshot(AiThread $thread, string $prompt): void
    {
        $updated = $this->threadService->update($thread, [
            'prompt_snapshot' => $prompt,
        ]);

        $thread->prompt_snapshot = $updated->prompt_snapshot;
    }

    protected function promptSnapshotsEnabled(): bool
    {
        return (bool) config('atlas-nexus.threads.snapshot_prompts', true);
    }

    protected function normalizePrompt(?string $prompt): ?string
    {
        if ($prompt === null) {
            return null;
        }

        return $prompt === '' ? null : $prompt;
    }
}
