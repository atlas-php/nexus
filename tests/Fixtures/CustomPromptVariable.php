<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Fixtures;

use Atlas\Nexus\Contracts\PromptVariableGroup;
use Atlas\Nexus\Services\Prompts\PromptVariableContext;

/**
 * Class CustomPromptVariable
 *
 * Test-only variable that exposes the thread id placeholder.
 */
class CustomPromptVariable implements PromptVariableGroup
{
    public function variables(PromptVariableContext $context): array
    {
        return [
            'THREAD.ID' => (string) $context->thread()->id,
        ];
    }
}
