<?php

declare(strict_types=1);

namespace Atlas\Nexus\Support\Prompts\Variables;

use Atlas\Nexus\Contracts\PromptVariableGroup;
use Atlas\Nexus\Support\Prompts\PromptVariableContext;
use Illuminate\Support\Collection;

/**
 * Class MemoryPromptVariables
 *
 * Provides a formatted contextual memory block that prompts can opt into via the MEMORY.CONTEXT variable.
 */
class MemoryPromptVariables implements PromptVariableGroup
{
    /**
     * @return array<string, string>
     */
    public function variables(PromptVariableContext $context): array
    {
        $memories = $context->threadState()->memories;

        $formatted = $this->formatContext($memories);

        if ($formatted === null) {
            return [];
        }

        return ['MEMORY.CONTEXT' => $formatted];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $memories
     */
    private function formatContext(Collection $memories): ?string
    {
        $lines = $memories
            ->map(function (array $memory): ?string {
                $content = $this->stringValue($memory['content'] ?? null);

                if ($content === null) {
                    return null;
                }

                $threadId = $memory['thread_id'] ?? null;
                $prefix = is_int($threadId) ? sprintf('(thread %d) ', $threadId) : '';

                return sprintf('- %s%s', $prefix, $content);
            })
            ->filter(static fn (?string $line): bool => $line !== null)
            ->all();

        if ($lines === []) {
            return null;
        }

        return "Contextual memories:\n".implode("\n", $lines);
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
