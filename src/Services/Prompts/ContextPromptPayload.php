<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Prompts;

/**
 * Class ContextPromptPayload
 *
 * Carries the rendered context prompt text along with the supporting metadata so assistants can decide
 * whether the message is worth delivering to a given thread.
 */
class ContextPromptPayload
{
    /**
     * @param  array<int, string>  $memories
     */
    public function __construct(
        private readonly string $content,
        private readonly ?string $summary,
        private readonly array $memories
    ) {}

    public function content(): string
    {
        return $this->content;
    }

    public function summary(): ?string
    {
        return $this->summary;
    }

    /**
     * @return array<int, string>
     */
    public function memories(): array
    {
        return $this->memories;
    }
}
