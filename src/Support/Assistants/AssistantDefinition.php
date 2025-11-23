<?php

declare(strict_types=1);

namespace Atlas\Nexus\Support\Assistants;

/**
 * Class AssistantDefinition
 *
 * Baseline DTO-style definition that describes an assistant and its prompt metadata.
 * Consumers extend this class and register implementations via config to keep assistants
 * declarative and application-owned.
 */
abstract class AssistantDefinition
{
    abstract public function key(): string;

    abstract public function name(): string;

    abstract public function systemPrompt(): string;

    public function description(): ?string
    {
        return null;
    }

    public function defaultModel(): ?string
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

    public function isActive(): bool
    {
        return true;
    }

    public function isHidden(): bool
    {
        return false;
    }

    /**
     * @return array<int, string>
     */
    public function tools(): array
    {
        return [];
    }

    /**
     * @return array<int, string>
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

    public function promptIsActive(): bool
    {
        return true;
    }

    public function promptUserId(): ?int
    {
        return null;
    }

    /**
     * @return array<string, mixed>
     */
    final public function assistantAttributes(): array
    {
        return [
            'assistant_key' => $this->key(),
            'key' => $this->key(),
            'name' => $this->name(),
            'description' => $this->description(),
            'default_model' => $this->defaultModel(),
            'temperature' => $this->temperature(),
            'top_p' => $this->topP(),
            'max_output_tokens' => $this->maxOutputTokens(),
            'is_active' => $this->isActive(),
            'is_hidden' => $this->isHidden(),
            'tools' => $this->normalizeStringArray($this->tools()),
            'provider_tools' => $this->normalizeStringArray($this->providerTools()),
            'metadata' => $this->normalizeMetadata($this->metadata()),
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
     * @param  array<int, mixed>  $values
     * @return array<int, string>|null
     */
    final protected function normalizeStringArray(array $values): ?array
    {
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

        $keys = array_keys($normalized);

        return $keys !== [] ? $keys : null;
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
}
