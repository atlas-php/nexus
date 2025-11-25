<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads\Logging;

/**
 * Class ToolInvocation
 *
 * Represents a tool usage within a conversation, including arguments and resulting output.
 */
class ToolInvocation
{
    /**
     * @param  array<string, mixed>  $arguments
     * @param  array<string, mixed>|int|float|string|null  $result
     */
    public function __construct(
        private readonly string $toolName,
        private readonly array $arguments,
        private readonly int|float|string|array|null $result
    ) {}

    public function toolName(): string
    {
        return $this->toolName;
    }

    /**
     * @return array<string, mixed>
     */
    public function arguments(): array
    {
        return $this->arguments;
    }

    /**
     * @return array<string, mixed>|int|float|string|null
     */
    public function result(): int|float|string|array|null
    {
        return $this->result;
    }
}
