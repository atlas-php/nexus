<?php

declare(strict_types=1);

namespace Atlas\Nexus\Support\Assistants;

/**
 * Class ResolvedAssistant
 *
 * Provides normalized assistant data derived from an AssistantDefinition instance.
 */
class ResolvedAssistant
{
    private string $key;

    private string $name;

    private ?string $description;

    private ?string $model;

    private ?float $temperature;

    private ?float $topP;

    private ?int $maxOutputTokens;

    private ?int $maxDefaultSteps;

    private bool $isActive;

    private bool $isHidden;

    /**
     * @var array<int, string>
     */
    private array $tools;

    /**
     * @var array<int, string>
     */
    private array $providerTools;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $toolConfigurations;

    /**
     * @var array<string, array<string, mixed>>
     */
    private array $providerToolConfigurations;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $metadata;

    /**
     * @var array<string, mixed>|null
     */
    private ?array $reasoningOptions;

    public function __construct(private readonly AssistantDefinition $definition)
    {
        $attributes = $definition->assistantAttributes();

        $key = $attributes['assistant_key'] ?? $attributes['key'] ?? null;

        $this->key = is_string($key) && $key !== '' ? strtolower($key) : strtolower($definition->key());
        $this->name = (string) ($attributes['name'] ?? $definition->name());
        $this->description = $this->normalizeNullableString($attributes['description'] ?? null);
        $this->model = $this->normalizeNullableString($attributes['default_model'] ?? null);
        $this->temperature = $this->normalizeNullableFloat($attributes['temperature'] ?? null);
        $this->topP = $this->normalizeNullableFloat($attributes['top_p'] ?? null);
        $this->maxOutputTokens = $this->normalizeNullableInt($attributes['max_output_tokens'] ?? null);
        $this->maxDefaultSteps = $this->normalizeNullableInt($attributes['max_default_steps'] ?? null);
        $this->isActive = (bool) ($attributes['is_active'] ?? true);
        $this->isHidden = (bool) ($attributes['is_hidden'] ?? false);
        $this->tools = $this->normalizeStringArray($attributes['tools'] ?? null);
        $this->providerTools = $this->normalizeStringArray($attributes['provider_tools'] ?? null);
        $this->toolConfigurations = $this->normalizeConfigurationMap($attributes['tool_configuration'] ?? null);
        $this->providerToolConfigurations = $this->normalizeConfigurationMap($attributes['provider_tool_configuration'] ?? null);
        $this->metadata = $this->normalizeMetadata($attributes['metadata'] ?? null);
        $this->reasoningOptions = $this->normalizeConfigurationArray($attributes['reasoning'] ?? null);
    }

    public function definition(): AssistantDefinition
    {
        return $this->definition;
    }

    public function key(): string
    {
        return $this->key;
    }

    public function name(): string
    {
        return $this->name;
    }

    public function description(): ?string
    {
        return $this->description;
    }

    public function model(): ?string
    {
        return $this->model;
    }

    public function temperature(): ?float
    {
        return $this->temperature;
    }

    public function topP(): ?float
    {
        return $this->topP;
    }

    public function maxOutputTokens(): ?int
    {
        return $this->maxOutputTokens;
    }

    public function maxDefaultSteps(): ?int
    {
        return $this->maxDefaultSteps;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function isHidden(): bool
    {
        return $this->isHidden;
    }

    /**
     * @return array<int, string>
     */
    public function tools(): array
    {
        return $this->tools;
    }

    /**
     * @return array<int, string>
     */
    public function providerTools(): array
    {
        return $this->providerTools;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function toolConfigurations(): array
    {
        return $this->toolConfigurations;
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    public function providerToolConfigurations(): array
    {
        return $this->providerToolConfigurations;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function toolConfiguration(string $key): ?array
    {
        $normalized = trim($key);

        return $normalized === '' ? null : ($this->toolConfigurations[$normalized] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function providerToolConfiguration(string $key): ?array
    {
        $normalized = trim($key);

        return $normalized === '' ? null : ($this->providerToolConfigurations[$normalized] ?? null);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function metadata(): ?array
    {
        return $this->metadata;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function reasoning(): ?array
    {
        return $this->reasoningOptions;
    }

    public function systemPrompt(): string
    {
        return $this->definition->systemPrompt();
    }

    private function normalizeNullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    private function normalizeNullableFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (float) $value : null;
    }

    private function normalizeNullableInt(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return is_numeric($value) ? (int) $value : null;
    }

    /**
     * @param  array<int, mixed>|null  $values
     * @return array<int, string>
     */
    private function normalizeStringArray(?array $values): array
    {
        if ($values === null) {
            return [];
        }

        $normalized = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);

            if ($trimmed === '') {
                continue;
            }

            $normalized[$trimmed] = true;
        }

        return array_keys($normalized);
    }

    /**
     * @param  array<string|int, mixed>|null  $metadata
     * @return array<string, mixed>|null
     */
    private function normalizeMetadata(?array $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        $normalized = [];

        foreach ($metadata as $key => $value) {
            if (! is_string($key) || $key === '') {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized === [] ? null : $normalized;
    }

    /**
     * @phpstan-param array<string, array<string, mixed>>|null  $config
     *
     * @return array<string, array<string, mixed>>
     */
    private function normalizeConfigurationMap(mixed $config): array
    {
        if (! is_array($config)) {
            return [];
        }

        $normalized = [];

        foreach ($config as $key => $value) {
            if ($key === '') {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>|null  $config
     * @return array<string, mixed>|null
     */
    private function normalizeConfigurationArray(?array $config): ?array
    {
        if ($config === null) {
            return null;
        }

        $normalized = [];

        foreach ($config as $key => $value) {
            if (! is_string($key)) {
                continue;
            }

            $trimmed = trim($key);

            if ($trimmed === '') {
                continue;
            }

            $normalized[$trimmed] = $value;
        }

        return $normalized === [] ? null : $normalized;
    }
}
