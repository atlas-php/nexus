<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Agents;

use Atlas\Nexus\Models\AiThread;

/**
 * Class AgentDefinition
 *
 * Baseline DTO-style definition that describes an agent and its prompt metadata.
 * Consumers extend this class and register implementations via config to keep agents
 * declarative and application-owned.
 */
abstract class AgentDefinition
{
    abstract public function key(): string;

    abstract public function name(): string;

    abstract public function systemPrompt(): string;

    public function description(): ?string
    {
        return null;
    }

    public function contextPrompt(): ?string
    {
        return null;
    }

    public function model(): ?string
    {
        return null;
    }

    public function temperature(): ?float
    {
        return null;
    }

    public function topP(): ?float
    {
        return null;
    }

    public function maxOutputTokens(): ?int
    {
        return null;
    }

    public function maxDefaultSteps(): ?int
    {
        return null;
    }

    public function isActive(): bool
    {
        return true;
    }

    public function isHidden(): bool
    {
        return false;
    }

    /**
     * @return array<int|string, string|array<string, mixed>>
     */
    public function tools(): array
    {
        return [];
    }

    /**
     * @return array<int|string, string|array<string, mixed>>
     */
    public function providerTools(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return [];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function reasoning(): ?array
    {
        return null;
    }

    public function promptIsActive(): bool
    {
        return true;
    }

    public function promptUserId(): ?int
    {
        return null;
    }

    public function isContextAvailable(AiThread $thread): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    final public function agentAttributes(): array
    {
        [$toolKeys, $toolConfigurations] = $this->normalizeToolDeclarations($this->tools());
        [$providerToolKeys, $providerToolConfigurations] = $this->normalizeToolDeclarations($this->providerTools());
        $reasoning = $this->reasoning();

        return [
            'agent_key' => $this->key(),
            'assistant_key' => $this->key(),
            'key' => $this->key(),
            'name' => $this->name(),
            'description' => $this->description(),
            'context_prompt' => $this->contextPrompt(),
            'default_model' => $this->model(),
            'temperature' => $this->temperature(),
            'top_p' => $this->topP(),
            'max_output_tokens' => $this->maxOutputTokens(),
            'max_default_steps' => $this->maxDefaultSteps(),
            'is_active' => $this->isActive(),
            'is_hidden' => $this->isHidden(),
            'tools' => $toolKeys,
            'tool_configuration' => $toolConfigurations,
            'provider_tools' => $providerToolKeys,
            'provider_tool_configuration' => $providerToolConfigurations,
            'metadata' => $this->normalizeMetadata($this->metadata()),
            'reasoning' => is_array($reasoning) ? $this->normalizeConfigurationArray($reasoning) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    final public function promptAttributes(): array
    {
        return [
            'system_prompt' => $this->systemPrompt(),
            'is_active' => $this->promptIsActive(),
            'user_id' => $this->promptUserId(),
        ];
    }

    /**
     * @param  array<string|int, mixed>  $metadata
     * @return array<string, mixed>|null
     */
    final protected function normalizeMetadata(array $metadata): ?array
    {
        $normalized = [];

        foreach ($metadata as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized !== [] ? $normalized : null;
    }

    /**
     * @param  array<int|string, string|array<string, mixed>>  $declarations
     * @return array{0: array<int, string>|null, 1: array<string, array<string, mixed>>|null}
     */
    final protected function normalizeToolDeclarations(array $declarations): array
    {
        $keys = [];
        $configurations = [];

        foreach ($declarations as $index => $value) {
            $toolKey = null;
            $config = null;

            if (is_int($index)) {
                if (is_string($value)) {
                    $toolKey = trim($value);
                } elseif (is_array($value)) {
                    $toolKey = $this->normalizeToolKeyFromDeclaration($value);
                    $config = $this->extractToolConfigFromDeclaration($value);
                }
            } else {
                $toolKey = trim((string) $index);
                $config = is_array($value) ? $value : null;
            }

            if ($toolKey === null || $toolKey === '') {
                continue;
            }

            $keys[$toolKey] = true;

            if ($config !== null) {
                $normalized = $this->normalizeConfigurationArray($config);

                if ($normalized !== null) {
                    $configurations[$toolKey] = $normalized;
                }
            }
        }

        return [
            $keys !== [] ? array_keys($keys) : null,
            $configurations !== [] ? $configurations : null,
        ];
    }

    /**
     * @param  array<string|int, mixed>|null  $configuration
     * @return array<string, mixed>|null
     */
    final protected function normalizeConfigurationArray(?array $configuration): ?array
    {
        if ($configuration === null) {
            return null;
        }

        $normalized = [];

        foreach ($configuration as $key => $value) {
            $normalizedKey = (string) $key;

            if ($normalizedKey === '') {
                continue;
            }

            $normalized[$normalizedKey] = $value;
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @param  array<string, mixed>  $declaration
     */
    private function normalizeToolKeyFromDeclaration(array $declaration): ?string
    {
        $key = $declaration['key'] ?? $declaration['tool'] ?? null;

        if (is_string($key)) {
            $normalized = trim($key);

            return $normalized !== '' ? $normalized : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $declaration
     * @return array<string, mixed>|null
     */
    private function extractToolConfigFromDeclaration(array $declaration): ?array
    {
        $config = $declaration['config'] ?? $declaration['configuration'] ?? $declaration['options'] ?? null;

        if (! is_array($config)) {
            $config = $declaration;
            unset($config['key'], $config['tool'], $config['config'], $config['configuration'], $config['options']);
        }

        return $config === [] ? null : $config;
    }
}
