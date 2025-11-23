<?php

declare(strict_types=1);

namespace Atlas\Nexus\Support\Prompts;

use Atlas\Nexus\Models\AiAssistantPrompt;

/**
 * Class PromptSnapshot
 *
 * Captures the rendered prompt text and resolved variable values used to freeze a thread's system prompt.
 */
class PromptSnapshot
{
    /**
     * @param  array<string, mixed>  $prompt
     * @param  array<string, string>  $variables
     */
    public function __construct(
        public readonly int $promptId,
        public readonly array $prompt,
        public readonly array $variables,
        public readonly string $renderedSystemPrompt
    ) {}

    public static function fromArray(mixed $snapshot): ?self
    {
        if (! is_array($snapshot)) {
            return null;
        }

        $promptId = $snapshot['prompt_id'] ?? null;
        $prompt = $snapshot['prompt'] ?? null;
        $rendered = $snapshot['rendered_system_prompt'] ?? null;
        $variables = $snapshot['variables'] ?? [];

        if (! is_numeric($promptId) || ! is_array($prompt) || ! is_string($rendered)) {
            return null;
        }

        return new self(
            (int) $promptId,
            $prompt,
            is_array($variables) ? self::normalizeVariables($variables) : [],
            $rendered
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'prompt_id' => $this->promptId,
            'prompt' => $this->prompt,
            'variables' => $this->variables,
            'rendered_system_prompt' => $this->renderedSystemPrompt,
        ];
    }

    public function toPromptModel(): AiAssistantPrompt
    {
        $prompt = new AiAssistantPrompt;
        $raw = $this->prompt;
        $raw['system_prompt'] = $raw['system_prompt'] ?? $this->renderedSystemPrompt;

        $prompt->forceFill($raw);
        $prompt->setAttribute('id', $this->promptId);
        $prompt->exists = false;

        return $prompt;
    }

    public function rawSystemPrompt(): string
    {
        $raw = $this->prompt['system_prompt'] ?? null;

        return is_string($raw) && $raw !== '' ? $raw : $this->renderedSystemPrompt;
    }

    /**
     * @param  array<string|int, mixed>  $variables
     * @return array<string, string>
     */
    private static function normalizeVariables(array $variables): array
    {
        $normalized = [];

        foreach ($variables as $key => $value) {
            $normalizedKey = (string) $key;

            if ($normalizedKey === '') {
                continue;
            }

            if (! is_string($value)) {
                continue;
            }

            $normalized[$normalizedKey] = $value;
        }

        return $normalized;
    }
}
