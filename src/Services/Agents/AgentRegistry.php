<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Agents;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Class AgentRegistry
 *
 * Instantiates and exposes agent definitions configured by the consuming application.
 */
class AgentRegistry
{
    /**
     * @var array<string, ResolvedAgent>
     */
    private array $resolved = [];

    public function __construct(
        private readonly Container $container,
        private readonly ConfigRepository $config
    ) {}

    /**
     * @return array<string, ResolvedAgent>
     */
    public function all(): array
    {
        $this->warm();

        return $this->resolved;
    }

    /**
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function refresh(): void
    {
        $this->resolved = [];
    }

    public function find(string $key): ?ResolvedAgent
    {
        $this->warm();

        $normalizedKey = trim(strtolower($key));

        return $this->resolved[$normalizedKey] ?? null;
    }

    public function require(string $key): ResolvedAgent
    {
        $agent = $this->find($key);

        if ($agent === null) {
            throw new RuntimeException(sprintf('Agent definition [%s] is not registered.', $key));
        }

        return $agent;
    }

    private function warm(): void
    {
        if ($this->resolved !== []) {
            return;
        }

        foreach ($this->configuredDefinitions() as $class) {
            $instance = $this->container->make($class);

            if (! $instance instanceof AgentDefinition) {
                continue;
            }

            $agent = new ResolvedAgent($instance);
            $this->resolved[$agent->key()] = $agent;
        }
    }

    /**
     * @return array<int, string>
     */
    private function configuredDefinitions(): array
    {
        $defaultAgents = $this->normalizeDefinitionList(
            $this->config->get('atlas-nexus.defaults.agents', [])
        );

        $agentDefinitions = $this->normalizeDefinitionList(
            $this->config->get('atlas-nexus.agents')
        );

        $assistantDefinitions = $this->normalizeDefinitionList(
            $this->config->get('atlas-nexus.assistants')
        );

        $assistantsAreCustom = $assistantDefinitions !== [] && $defaultAgents !== [] && $assistantDefinitions !== $defaultAgents;

        if ($assistantsAreCustom) {
            return $assistantDefinitions;
        }

        if ($agentDefinitions !== []) {
            return $agentDefinitions;
        }

        if ($assistantDefinitions !== []) {
            return $assistantDefinitions;
        }

        return $defaultAgents;
    }

    /**
     * @return array<int, string>
     */
    private function normalizeDefinitionList(mixed $configured): array
    {
        if (! is_array($configured)) {
            return [];
        }

        $definitions = [];

        foreach ($configured as $class) {
            if (! is_string($class) || $class === '') {
                continue;
            }

            $definitions[] = $class;
        }

        return $definitions;
    }
}
