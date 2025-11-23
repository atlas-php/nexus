<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Assistants;

use Atlas\Nexus\Support\Assistants\AssistantDefinition;
use Atlas\Nexus\Support\Assistants\ResolvedAssistant;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;
use RuntimeException;

/**
 * Class AssistantRegistry
 *
 * Instantiates and exposes assistant definitions configured by the consuming application.
 */
class AssistantRegistry
{
    /**
     * @var array<string, ResolvedAssistant>
     */
    private array $resolved = [];

    public function __construct(
        private readonly Container $container,
        private readonly ConfigRepository $config
    ) {}

    /**
     * @return array<string, ResolvedAssistant>
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

    public function find(string $key): ?ResolvedAssistant
    {
        $this->warm();

        $normalizedKey = trim(strtolower($key));

        return $this->resolved[$normalizedKey] ?? null;
    }

    public function require(string $key): ResolvedAssistant
    {
        $assistant = $this->find($key);

        if ($assistant === null) {
            throw new RuntimeException(sprintf('Assistant definition [%s] is not registered.', $key));
        }

        return $assistant;
    }

    private function warm(): void
    {
        if ($this->resolved !== []) {
            return;
        }

        $configured = $this->config->get('atlas-nexus.assistants', []);

        if (! is_array($configured)) {
            return;
        }

        foreach ($configured as $class) {
            if (! is_string($class) || $class === '') {
                continue;
            }

            $instance = $this->container->make($class);

            if (! $instance instanceof AssistantDefinition) {
                continue;
            }

            $assistant = new ResolvedAssistant($instance);
            $this->resolved[$assistant->key()] = $assistant;
        }

    }
}
