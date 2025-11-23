<?php

declare(strict_types=1);

namespace Atlas\Nexus\Support\Prompts\Variables;

use Atlas\Nexus\Contracts\PromptVariableGroup;
use Atlas\Nexus\Support\Prompts\PromptVariableContext;
use Illuminate\Support\Carbon;

/**
 * Class ThreadPromptVariables
 *
 * Exposes the current thread identifiers and summaries plus a UTC timestamp placeholder for prompts.
 */
class ThreadPromptVariables implements PromptVariableGroup
{
    /**
     * @return array<string, string|null>
     */
    public function variables(PromptVariableContext $context): array
    {
        $thread = $context->thread();

        return [
            'THREAD.ID' => (string) $thread->getKey(),
            'THREAD.TITLE' => $this->normalizeValue($thread->title),
            'THREAD.SUMMARY' => $this->normalizeValue($thread->summary),
            'THREAD.LONG_SUMMARY' => $this->normalizeValue($thread->long_summary),
            'DATETIME' => Carbon::now('UTC')->toIso8601String(),
        ];
    }

    private function normalizeValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        if (trim($value) === '') {
            return null;
        }

        return $value;
    }
}
