<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Tools;

use Atlas\Nexus\Support\Tools\ProviderToolDefinition;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Class ProviderToolRegistry
 *
 * Loads provider-level tool definitions from configuration and exposes them for assistant filtering.
 */
class ProviderToolRegistry
{
    /**
     * @var array<string, ProviderToolDefinition>
     */
    private array $definitions = [];

    public function __construct(
        private readonly ConfigRepository $config
    ) {
        $this->registerConfigured();
    }

    public function definition(string $key): ?ProviderToolDefinition
    {
        return $this->definitions[$key] ?? null;
    }

    /**
     * @return array<string, ProviderToolDefinition>
     */
    public function all(): array
    {
        return $this->definitions;
    }

    /**
     * @param  array<int, string>  $keys
     * @return array<int, ProviderToolDefinition>
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

    private function registerConfigured(): void
    {
        $configured = $this->config->get('atlas-nexus.provider_tools', []);

        if (! is_array($configured)) {
            return;
        }

        foreach ($configured as $key => $options) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            if (! is_array($options)) {
                continue;
            }

            $this->definitions[$key] = new ProviderToolDefinition($key, $options);
        }
    }
}
