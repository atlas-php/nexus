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

    private ?string $defaultModel;

    private ?float $temperature;

    private ?float $topP;

    private ?int $maxOutputTokens;

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
     * @var array<string, mixed>|null
     */
    private ?array $metadata;

    public function __construct(private readonly AssistantDefinition $definition)
    {
        $attributes = $definition->assistantAttributes();

        $key = $attributes['assistant_key'] ?? $attributes['key'] ?? null;

        $this->key = is_string($key) && $key !== '' ? strtolower($key) : strtolower($definition->key());
        $this->name = (string) ($attributes['name'] ?? $definition->name());
        $this->description = $this->normalizeNullableString($attributes['description'] ?? null);
        $this->defaultModel = $this->normalizeNullableString($attributes['default_model'] ?? null);
        $this->temperature = $this->normalizeNullableFloat($attributes['temperature'] ?? null);
        $this->topP = $this->normalizeNullableFloat($attributes['top_p'] ?? null);
        $this->maxOutputTokens = $this->normalizeNullableInt($attributes['max_output_tokens'] ?? null);
        $this->isActive = (bool) ($attributes['is_active'] ?? true);
        $this->isHidden = (bool) ($attributes['is_hidden'] ?? false);
        $this->tools = $this->normalizeStringArray($attributes['tools'] ?? null);
        $this->providerTools = $this->normalizeStringArray($attributes['provider_tools'] ?? null);
        $this->metadata = $this->normalizeMetadata($attributes['metadata'] ?? null);
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

    public function defaultModel(): ?string
    {
        return $this->defaultModel;
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
     * @return array<string, mixed>|null
     */
    public function metadata(): ?array
    {
        return $this->metadata;
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
}
