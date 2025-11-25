<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Fixtures\Prompts;

use Atlas\Nexus\Support\Prompts\ContextPrompt;

/**
 * Class CustomContextPrompt
 *
 * Provides a simplified template override for testing configurable context prompts.
 */
class CustomContextPrompt extends ContextPrompt
{
    protected function promptTemplate(): string
    {
        return 'Summary:{CONTEXT_PROMPT.LAST_SUMMARY}|Memories:{CONTEXT_PROMPT.MEMORIES}';
    }
}
