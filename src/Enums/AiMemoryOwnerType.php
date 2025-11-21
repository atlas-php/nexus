<?php

declare(strict_types=1);

namespace Atlas\Nexus\Enums;

/**
 * Identifies the entity scope associated with a memory record.
 */
enum AiMemoryOwnerType: string
{
    case USER = 'user';
    case ASSISTANT = 'assistant';
    case ORG = 'org';
}
