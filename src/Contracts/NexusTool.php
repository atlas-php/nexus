<?php

declare(strict_types=1);

namespace Atlas\Nexus\Contracts;

use Atlas\Nexus\Integrations\Prism\Tools\ToolParameter;
use Atlas\Nexus\Integrations\Prism\Tools\ToolResponse;
use Prism\Prism\Tool as PrismTool;

/**
 * Interface NexusTool
 *
 * Defines the contract Nexus tools must follow to expose Prism-compatible tooling.
 */
interface NexusTool
{
    public function name(): string;

    public function description(): string;

    /**
     * Convert the tool into a Prism-ready instance with configured parameters and handlers.
     */
    public function toPrismTool(): PrismTool;

    /**
     * @return array<int, ToolParameter>
     */
    public function parameters(): array;

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function handle(array $arguments): ToolResponse;
}
