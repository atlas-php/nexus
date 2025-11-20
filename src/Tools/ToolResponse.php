<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tools;

/**
 * Class ToolResponse
 *
 * Represents the normalized output from a Nexus tool for Prism consumption.
 */
class ToolResponse
{
    /**
     * @param  array<string, mixed>  $meta
     */
    public function __construct(
        private readonly string $message,
        private readonly array $meta = []
    ) {}

    public function message(): string
    {
        return $this->message;
    }

    /**
     * @return array<string, mixed>
     */
    public function meta(): array
    {
        return $this->meta;
    }
}
