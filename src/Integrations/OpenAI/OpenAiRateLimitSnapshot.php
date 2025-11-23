<?php

declare(strict_types=1);

namespace Atlas\Nexus\Integrations\OpenAI;

/**
 * Class OpenAiRateLimitSnapshot
 *
 * Captures a collection of OpenAI rate limits returned from the limits endpoint alongside the raw payload.
 */
class OpenAiRateLimitSnapshot
{
    /**
     * @param  array<int, OpenAiRateLimit>  $limits
     * @param  array<string, mixed>|array<int, mixed>  $rawPayload
     */
    public function __construct(
        public readonly array $limits,
        public readonly array $rawPayload,
    ) {}

    public function isEmpty(): bool
    {
        return $this->limits === [];
    }

    public function describe(): string
    {
        if ($this->isEmpty()) {
            return 'unavailable';
        }

        $segments = array_map(
            static fn (OpenAiRateLimit $limit): string => $limit->describe(),
            $this->limits
        );

        return '['.implode(', ', $segments).']';
    }
}
