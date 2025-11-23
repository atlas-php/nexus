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

        if ($memories->isEmpty()) {
            return [];
        }

        return ['MEMORY.CONTEXT' => $this->formatContext($memories)];
    }

    /**
     * @param  Collection<int, \Atlas\Nexus\Models\AiMemory>  $memories
     */
    private function formatContext(Collection $memories): string
    {
        $lines = $memories
            ->map(static fn ($memory): string => sprintf(
                '- (%s) %s',
                $memory->kind,
                $memory->content
            ))
            ->all();

        return "Contextual memories:\n".implode("\n", $lines);
    }
}
