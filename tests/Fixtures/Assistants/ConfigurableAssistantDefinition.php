<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Fixtures\Assistants;

use Atlas\Nexus\Support\Assistants\AssistantDefinition;

/**
 * Provides configurable assistant definitions for tests without requiring database records.
 */
abstract class ConfigurableAssistantDefinition extends AssistantDefinition
{
    /**
     * @var array<class-string<static>, array<string, mixed>>
     */
    private static array $configStore = [];

    public function __construct()
    {
        if (! isset(self::$configStore[static::class])) {
            self::$configStore[static::class] = static::defaults();
        }
    }

    public static function resetConfig(): void
    {
        self::$configStore[static::class] = static::defaults();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    public static function updateConfig(array $overrides): void
    {
        self::$configStore[static::class] = array_merge(static::defaults(), $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    abstract protected static function defaults(): array;

    protected function data(string $key, mixed $default = null): mixed
    {
        $config = self::$configStore[static::class] ?? static::defaults();

        return $config[$key] ?? $default;
    }

    public function key(): string
    {
        return (string) $this->data('key', static::class);
    }

    public function name(): string
    {
        return (string) $this->data('name', 'Test Assistant');
    }

    public function description(): ?string
    {
        return $this->data('description');
    }

    public function systemPrompt(): string
    {
        return (string) $this->data('system_prompt', 'You are a test assistant.');
    }

    public function model(): ?string
    {
        return $this->data('default_model');
    }

    public function temperature(): ?float
    {
        return $this->data('temperature');
    }

    public function topP(): ?float
    {
        return $this->data('top_p');
    }

    public function maxOutputTokens(): ?int
    {
        return $this->data('max_output_tokens');
    }

    public function maxDefaultSteps(): ?int
    {
        return $this->data('max_default_steps');
    }

    public function isActive(): bool
    {
        return (bool) $this->data('is_active', true);
    }

    public function isHidden(): bool
    {
        return (bool) $this->data('is_hidden', false);
    }

    /**
     * @return array<int|string, string|array<string, mixed>>
     */
    public function tools(): array
    {
        return $this->data('tools', []);
    }

    /**
     * @return array<int|string, string|array<string, mixed>>
     */
    public function providerTools(): array
    {
        return $this->data('provider_tools', []);
    }

    /**
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        return $this->data('metadata', []);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function reasoning(): ?array
    {
        $options = $this->data('reasoning');

        return is_array($options) ? $options : null;
    }

    public function promptIsActive(): bool
    {
        return (bool) $this->data('prompt_is_active', true);
    }
}
