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
use Atlas\Nexus\Support\Prompts\PromptSnapshot;
use Atlas\Nexus\Support\Prompts\PromptVariableContext;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Class ThreadStateService
 *
 * Builds a snapshot of a thread's state, including prompt, eligible messages, memories, tool access, and rendered system prompt.
 */
class ThreadStateService
{
    protected bool $includeMemoryTool;

    protected bool $freezeThread;

    public function __construct(
        private readonly AiMessageService $messageService,
        private readonly AiMemoryService $memoryService,
        private readonly ProviderToolRegistry $providerToolRegistry,
        private readonly ToolRegistry $toolRegistry,
        private readonly PromptVariableService $promptVariableService,
        ConfigRepository $config
    ) {
        $this->includeMemoryTool = (bool) $config->get('atlas-nexus.tools.memory.enabled', true);
        $this->freezeThread = (bool) $config->get('atlas-nexus.prompts.freeze_thread', true);
    }

    public function forThread(AiThread $thread, ?bool $includeMemoryTool = null): ThreadState
    {
        $thread->loadMissing(['assistant', 'prompt', 'assistant.currentPrompt']);

        $assistant = $thread->assistant;

        if ($assistant === null) {
            throw new RuntimeException('Thread is missing an associated assistant.');
        }

        $snapshot = $this->snapshotFromThread($thread);
        $prompt = $this->resolvePrompt($thread, $assistant, $snapshot);
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
            $snapshot,
            null,
            $providerTools
        );

        if ($snapshot === null) {
            $snapshot = $this->buildPromptSnapshot($state);

            if ($this->freezeThread && $snapshot !== null) {
                $prompt = $snapshot->toPromptModel();
                $state = new ThreadState(
                    $thread,
                    $assistant,
                    $prompt,
                    $messages,
                    $memories,
                    $tools,
                    $snapshot,
                    null,
                    $providerTools
                );
            }
        }

        $systemPrompt = $this->resolveSystemPrompt($state);

        return new ThreadState(
            $thread,
            $assistant,
            $prompt,
            $messages,
            $memories,
            $tools,
            $snapshot,
            $systemPrompt,
            $providerTools
        );
    }

    protected function resolvePrompt(
        AiThread $thread,
        AiAssistant $assistant,
        ?PromptSnapshot $snapshot
    ): ?AiPrompt {
        if ($this->freezeThread && $snapshot !== null) {
            return $snapshot->toPromptModel();
        }

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

    protected function snapshotFromThread(AiThread $thread): ?PromptSnapshot
    {
        if (! $this->freezeThread) {
            return null;
        }

        if (! $this->hasSnapshotColumn($thread)) {
            return null;
        }

        return PromptSnapshot::fromArray($thread->prompt_snapshot);
    }

    protected function buildPromptSnapshot(ThreadState $state): ?PromptSnapshot
    {
        if (! $this->freezeThread || $state->prompt === null) {
            return null;
        }

        if (! $this->hasSnapshotColumn($state->thread)) {
            return null;
        }

        $promptId = $state->prompt->getAttribute('id');

        if (! is_numeric($promptId)) {
            return null;
        }

        $context = new PromptVariableContext($state, $state->prompt, $state->assistant);
        $render = $this->promptVariableService->renderWithVariables(
            $state->prompt->system_prompt,
            $context
        );

        $snapshot = new PromptSnapshot(
            (int) $promptId,
            $state->prompt->attributesToArray(),
            $render['variables'],
            $render['rendered_prompt']
        );

        $this->persistPromptSnapshot($state->thread, $snapshot);

        return $snapshot;
    }

    protected function persistPromptSnapshot(AiThread $thread, PromptSnapshot $snapshot): void
    {
        if (! $thread->exists) {
            return;
        }

        if (! $this->hasSnapshotColumn($thread)) {
            return;
        }

        $thread->forceFill(['prompt_snapshot' => $snapshot->toArray()]);
        $thread->save();
    }

    protected function resolveSystemPrompt(ThreadState $state): ?string
    {
        if ($this->freezeThread && $state->promptSnapshot !== null) {
            return $state->promptSnapshot->renderedSystemPrompt;
        }

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

    protected function hasSnapshotColumn(AiThread $thread): bool
    {
        $connection = $thread->getConnectionName()
            ?? config('atlas-nexus.database.connection')
            ?? config('database.default');

        return Schema::connection($connection)
            ->hasColumn($thread->getTable(), 'prompt_snapshot');
    }
}
