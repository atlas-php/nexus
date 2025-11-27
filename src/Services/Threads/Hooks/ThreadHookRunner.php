<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads\Hooks;

use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Services\Threads\Data\ThreadState;

/**
 * Class ThreadHookRunner
 *
 * Executes configured thread hooks for a given thread state and assistant response.
 */
class ThreadHookRunner
{
    public function __construct(private readonly ThreadHookRegistry $registry) {}

    public function run(ThreadState $state, AiMessage $assistantMessage): void
    {
        $hooks = $this->registry->all();

        if ($hooks === []) {
            return;
        }

        $context = new ThreadHookContext($state, $assistantMessage);

        foreach ($hooks as $hook) {
            $hook->handle($context);
        }
    }
}
