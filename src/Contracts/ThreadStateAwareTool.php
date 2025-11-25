<?php

declare(strict_types=1);

namespace Atlas\Nexus\Contracts;

use Atlas\Nexus\Services\Threads\Data\ThreadState;

/**
 * Interface ThreadStateAwareTool
 *
 * Allows Nexus tools to receive the current thread context before execution for scoped operations.
 */
interface ThreadStateAwareTool
{
    public function setThreadState(ThreadState $state): void;
}
