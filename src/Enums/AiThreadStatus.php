<?php

declare(strict_types=1);

namespace Atlas\Nexus\Enums;

/**
 * Enumerates conversation thread lifecycle states.
 */
enum AiThreadStatus: string
{
    case OPEN = 'open';
    case ARCHIVED = 'archived';
    case CLOSED = 'closed';
}
