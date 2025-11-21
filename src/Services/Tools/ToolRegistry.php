<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Tools;

use Atlas\Nexus\Support\Tools\ToolDefinition;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

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

    public function __construct(
        private readonly ConfigRepository $config
    ) {
        $this->registerConfigured();
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

    private function registerConfigured(): void
    {
        $configuredTools = $this->config->get('atlas-nexus.tools.registry', []);

        if (! is_array($configuredTools)) {
            return;
        }

        foreach ($configuredTools as $key => $handler) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $handlerClass = $this->resolveHandlerClass($handler);

            if ($handlerClass === null) {
                continue;
            }

            $this->register(new ToolDefinition($key, $handlerClass));
        }
    }

    private function resolveHandlerClass(mixed $handler): ?string
    {
        if (is_string($handler) && $handler !== '') {
            return $handler;
        }

        if (is_array($handler) && is_string($handler['handler'] ?? null) && $handler['handler'] !== '') {
            return $handler['handler'];
        }

        return null;
    }
}
