<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Prompts;

use Atlas\Nexus\Contracts\PromptVariableGroup;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;

/**
 * Class PromptVariableRegistry
 *
 * Registers and resolves prompt variable providers configured for the package consumer.
 */
class PromptVariableRegistry
{
    /**
     * @var array<string, PromptVariableGroup>
     */
    private array $variables = [];

    public function __construct(
        private readonly Application $app,
        private readonly ConfigRepository $config
    ) {
        $this->registerConfigured();
    }

    public function register(PromptVariableGroup $variable): void
    {
        $this->variables[spl_object_hash($variable)] = $variable;
    }

    public function definition(string $key): ?PromptVariableGroup
    {
        return $this->variables[$key] ?? null;
    }

    /**
     * @return array<string, PromptVariableGroup>
     */
    public function all(): array
    {
        return $this->variables;
    }

    /**
     * @return array<string, string>
     */
    public function resolveValues(PromptVariableContext $context): array
    {
        $resolved = [];

        foreach ($this->variables as $variable) {
            $resolved = array_merge(
                $resolved,
                $this->normalizeMultiple($variable->variables($context))
            );
        }

        return $resolved;
    }

    private function registerConfigured(): void
    {
        $configured = $this->config->get('atlas-nexus.variables', []);

        if (! is_array($configured)) {
            return;
        }

        foreach ($configured as $provider) {
            if (! is_string($provider) || $provider === '') {
                continue;
            }

            $variable = $this->app->make($provider);

            if (! $variable instanceof PromptVariableGroup) {
                continue;
            }

            $this->register($variable);
        }
    }

    /**
     * @param  array<string, string|null>  $values
     * @return array<string, string>
     */
    private function normalizeMultiple(array $values): array
    {
        $normalized = [];

        foreach ($values as $key => $value) {
            $normalizedKey = (string) $key;

            if ($normalizedKey === '') {
                continue;
            }

            if (! is_string($value) || $value === '') {
                continue;
            }

            $normalized[$normalizedKey] = $value;
        }

        return $normalized;
    }
}
