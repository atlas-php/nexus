<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads\Hooks;

use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Agents\ResolvedAgent;
use Atlas\Nexus\Services\Threads\Data\ThreadState;

/**
 * Class ThreadHookContext
 *
 * Provides contextual information needed by thread hooks when they run.
 */
class ThreadHookContext
{
    public function __construct(
        public readonly ThreadState $state,
        public readonly AiMessage $assistantMessage
    ) {}

    public function thread(): AiThread
    {
        return $this->state->thread;
    }

    public function agent(): ResolvedAgent
    {
        return $this->state->assistant;
    }
}
