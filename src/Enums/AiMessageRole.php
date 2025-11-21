<?php

declare(strict_types=1);

namespace Atlas\Nexus\Enums;

/**
 * Distinguishes chat message authorship roles.
 */
enum AiMessageRole: string
{
    case USER = 'user';
    case ASSISTANT = 'assistant';
}
