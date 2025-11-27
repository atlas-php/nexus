<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads\Hooks;

/**
 * Interface ThreadHook
 *
 * Represents a configurable hook that can run after an agent response to orchestrate follow-up workflows.
 */
interface ThreadHook
{
    public function key(): string;

    public function handle(ThreadHookContext $context): void;
}
