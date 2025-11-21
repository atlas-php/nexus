<?php

declare(strict_types=1);

namespace Atlas\Nexus\Enums;

/**
 * Captures the lifecycle of tool run execution.
 */
enum AiToolRunStatus: string
{
    case QUEUED = 'queued';
    case RUNNING = 'running';
    case SUCCEEDED = 'succeeded';
    case FAILED = 'failed';
}
