<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Tools;

use Atlas\Nexus\Integrations\Prism\Tools\MemoryTool;
use Atlas\Nexus\Integrations\Prism\Tools\ThreadFetcherTool;
use Atlas\Nexus\Integrations\Prism\Tools\ThreadSearchTool;
use Atlas\Nexus\Integrations\Prism\Tools\ThreadUpdaterTool;
use Atlas\Nexus\Support\Tools\ToolDefinition;

/**
 * Class ToolRegistry
 *
 * Maintains the set of code-defined tools addressable by assistants via their configured tool keys.
 */
class ToolRegistry
{
    /**
     * @var array<string, ToolDefinition>
     */
    private array $definitions = [];

    public function __construct()
    {
        $this->registerBuiltInTools();
    }

    public function register(ToolDefinition $definition): void
    {
        $this->definitions[$definition->key()] = $definition;
    }

    public function definition(string $key): ?ToolDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<int, ToolDefinition>
     */
    public function forKeys(array $keys): array
    {
        $uniqueKeys = array_values(array_unique(array_map(static fn ($key): string => (string) $key, $keys)));
        $definitions = [];

        foreach ($uniqueKeys as $key) {
            $definition = $this->definition($key);

            if ($definition === null) {
                continue;
            }

            $definitions[] = $definition;
        }

        return $definitions;
    }

    /**
     * @return array<string, ToolDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * Return all registered tool definitions keyed by tool key for UI listing.
     *
     * @return array<string, ToolDefinition>
     */
    public function available(): array
    {
        return $this->definitions;
    }

    private function registerBuiltInTools(): void
    {
        $definitions = [
            MemoryTool::definition(),
            ThreadSearchTool::definition(),
            ThreadFetcherTool::definition(),
            ThreadUpdaterTool::definition(),
        ];

        foreach ($definitions as $definition) {
            $this->register($definition);
        }
    }
}
