<?php

declare(strict_types=1);

namespace Atlas\Nexus\Enums;

/**
 * Captures the lifecycle state for assistant messages when responses are generated asynchronously.
 */
enum AiMessageStatus: string
{
    case PROCESSING = 'processing';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
