<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Tools;

use Atlas\Nexus\Contracts\NexusTool;

/**
 * Class ToolDefinition
 *
 * Represents a code-defined tool with a fixed key and handler class that can be resolved for execution.
 */
class ToolDefinition
{
    public function __construct(
        private readonly string $key,
        private readonly string $handlerClass
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    public function handlerClass(): string
    {
        return $this->handlerClass;
    }

    public function makeHandler(): ?NexusTool
    {
        if (! class_exists($this->handlerClass)) {
            return null;
        }

        /** @var NexusTool $handler */
        $handler = app($this->handlerClass);

        return $handler;
    }
}
