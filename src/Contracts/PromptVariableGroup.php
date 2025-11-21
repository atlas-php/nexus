<?php

declare(strict_types=1);

namespace Atlas\Nexus\Contracts;

use Atlas\Nexus\Support\Prompts\PromptVariableContext;

/**
 * Interface PromptVariableGroup
 *
 * Provides a group of prompt variables resolved together from shared context.
 */
interface PromptVariableGroup
{
    /**
     * @return array<string, string|null> Keyed placeholder values without curly braces.
     */
    public function variables(PromptVariableContext $context): array;
}
