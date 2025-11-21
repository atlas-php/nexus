<?php

declare(strict_types=1);

namespace Atlas\Nexus\Enums;

/**
 * Describes whether a thread originates from a user or a tool-run context.
 */
enum AiThreadType: string
{
    case USER = 'user';
    case TOOL = 'tool';
}
