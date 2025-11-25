<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Tools;

use Prism\Prism\ValueObjects\ProviderTool;

/**
 * Class ProviderToolDefinition
 *
 * Represents a provider-level tool configuration that can be passed directly to Prism requests.
 */
class ProviderToolDefinition
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function __construct(
        private readonly string $key,
        private readonly array $options = []
    ) {}

    public function key(): string
    {
        return $this->key;
    }

    /**
     * @return array<string, mixed>
     */
    public function options(): array
    {
        return $this->options;
    }

    public function toPrismProviderTool(): ProviderTool
    {
        return new ProviderTool(
            type: $this->key,
            options: $this->options
        );
    }
}
