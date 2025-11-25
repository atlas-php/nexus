<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Prompts;

use Atlas\Nexus\Enums\AiThreadType;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Assistants\ResolvedAssistant;
use Atlas\Nexus\Services\Models\AiThreadService;
use Atlas\Nexus\Services\Threads\Data\ThreadState;
use Atlas\Nexus\Services\Threads\ThreadMemoryService;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

use function array_map;
use function collect;
use function implode;
use function preg_replace;
use function sprintf;
use function trim;

/**
 * Class ContextPrompt
 *
 * Generates the introductory assistant-authored context message using prompt variables so consumers can
 * override a single template string while reusing contextual data.
 */
class ContextPrompt
{
    public function __construct(
        private readonly ThreadMemoryService $threadMemoryService,
        private readonly PromptVariableService $promptVariableService,
        private readonly AiThreadService $threadService
    ) {}

    public function compose(
        AiThread $thread,
        ResolvedAssistant $assistant,
        string $template
    ): ?ContextPromptPayload {
        if (! $this->shouldAttach($thread)) {
            return null;
        }

        $resolvedTemplate = $this->normalizeTemplate($template);

        if ($resolvedTemplate === null) {
            return null;
        }

        $userId = $thread->getAttribute('user_id');
        $memories = is_int($userId)
            ? $this->threadMemoryService->userMemories($userId, $thread->assistant_key)
            : new EloquentCollection;
        $summary = $this->summaryText($thread);
        $memoryStrings = $this->memoriesList($memories);

        $state = $this->threadState($thread, $assistant, $memories);
        $context = new PromptVariableContext($state);
        $customVariables = $this->customVariables($summary, $memoryStrings);
        $rendered = $this->promptVariableService->apply($resolvedTemplate, $context, $customVariables);
        $normalized = trim((string) preg_replace("/\n{3,}/", "\n\n", $rendered));

        if ($normalized === '') {
            return null;
        }

        return new ContextPromptPayload(
            $normalized,
            $summary,
            $memoryStrings
        );
    }

    protected function shouldAttach(AiThread $thread): bool
    {
        return $thread->type === AiThreadType::USER;
    }

    protected function summaryText(AiThread $thread): ?string
    {
        $previous = $this->previousThreadWithSummary($thread);

        if ($previous === null) {
            return null;
        }

        return $this->stringValue($previous->summary);
    }

    /**
     * @param  EloquentCollection<int, AiMemory>  $memories
     * @return array<int, string>
     */
    protected function memoriesList(EloquentCollection $memories): array
    {
        return $memories
            ->map(fn (AiMemory $memory): ?string => $this->stringValue($memory->content))
            ->filter(static fn (?string $value): bool => $value !== null)
            ->take($this->memoryLimit())
            ->values()
            ->all();
    }

    protected function memoryLimit(): int
    {
        return 8;
    }

    /**
     * @param  array<int, string>  $memories
     * @return array<string, string>
     */
    protected function customVariables(?string $summary, array $memories): array
    {
        $summarySection = $this->summarySection($summary);
        $memoriesSection = $this->memoriesSection($memories);

        return [
            'CONTEXT_PROMPT.LAST_SUMMARY' => $summary ?? 'No summary captured yet.',
            'CONTEXT_PROMPT.MEMORIES' => $memories === [] ? 'None recorded yet.' : implode("\n", $memories),
            'CONTEXT_PROMPT.LAST_SUMMARY_SECTION' => $summarySection,
            'CONTEXT_PROMPT.MEMORIES_SECTION' => $memoriesSection,
        ];
    }

    /**
     * @param  array<int, string>  $memories
     */
    protected function memoriesSection(array $memories): string
    {
        if ($memories === []) {
            return "latest memories for this user:\n- None.";
        }

        $lines = array_map(static fn (string $value): string => '- '.$value, $memories);

        return sprintf("latest memories for this user:\n%s", implode("\n", $lines));
    }

    protected function summarySection(?string $summary): string
    {
        if ($summary === null) {
            return "Last thread summary:\nNo summary";
        }

        return sprintf("Last thread summary:\n%s", $summary);
    }

    private function normalizeTemplate(string $template): ?string
    {
        $trimmed = trim($template);

        return $trimmed === '' ? null : $template;
    }

    /**
     * @param  Collection<int, AiMemory>  $memories
     */
    protected function threadState(
        AiThread $thread,
        ResolvedAssistant $assistant,
        Collection $memories
    ): ThreadState {
        return new ThreadState(
            $thread,
            $assistant,
            null,
            collect(),
            $memories,
            collect(),
            null,
            collect()
        );
    }

    protected function previousThreadWithSummary(AiThread $thread): ?AiThread
    {
        $query = $this->threadService->query()
            ->where('assistant_key', $thread->assistant_key)
            ->where('user_id', $thread->user_id)
            ->whereNotNull('summary')
            ->orderByRaw('COALESCE(last_message_at, updated_at, created_at) DESC');

        if ($thread->exists) {
            $query->where('id', '!=', $thread->getKey());
        }

        /** @var AiThread|null $match */
        $match = $query->first();

        if ($match instanceof AiThread) {
            return $match;
        }

        $currentSummary = $this->stringValue($thread->summary);

        return $currentSummary === null ? null : $thread;
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
