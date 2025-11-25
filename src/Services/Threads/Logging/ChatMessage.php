<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads\Logging;

/**
 * Class ChatMessage
 *
 * Captures a single chat message role and body for thread logging.
 */
class ChatMessage
{
    public function __construct(
        private readonly string $role,
        private readonly string $content
    ) {}

    public function role(): string
    {
        return $this->role;
    }

    public function content(): string
    {
        return $this->content;
    }
}
