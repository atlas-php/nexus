<?php

declare(strict_types=1);

namespace Atlas\Nexus\Enums;

/**
 * Enumerates supported message content encodings for chat logging.
 */
enum AiMessageContentType: string
{
    case TEXT = 'text';
    case JSON = 'json';
}
