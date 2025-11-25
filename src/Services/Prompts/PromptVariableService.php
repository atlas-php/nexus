<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Prompts;

use Stringable;

/**
 * Class PromptVariableService
 *
 * Applies configured prompt variables and ad-hoc replacements to system prompts before dispatching to the model provider.
 */
class PromptVariableService
{
    public function __construct(private readonly PromptVariableRegistry $registry) {}

    /**
     * @param  array<string, scalar|Stringable|null>  $customVariables
     */
    public function apply(string $prompt, PromptVariableContext $context, array $customVariables = []): string
    {
        $rendered = $this->renderWithVariables($prompt, $context, $customVariables);

        return $rendered['rendered_prompt'];
    }

    /**
     * @param  array<string, scalar|Stringable|null>  $customVariables
     * @return array{rendered_prompt: string, variables: array<string, string>}
     */
    public function renderWithVariables(
        string $prompt,
        PromptVariableContext $context,
        array $customVariables = []
    ): array {
        $variables = $this->mergeVariables($context, $customVariables);

        return [
            'rendered_prompt' => $this->renderPrompt($prompt, $variables),
            'variables' => $variables,
        ];
    }

    /**
     * @param  array<string, scalar|Stringable|null>  $customVariables
     * @return array<string, string>
     */
    public function resolvedVariables(PromptVariableContext $context, array $customVariables = []): array
    {
        return $this->mergeVariables($context, $customVariables);
    }

    /**
     * @param  array<string, scalar|Stringable|null>  $customVariables
     * @return array<string, string>
     */
    private function normalizeCustomVariables(array $customVariables): array
    {
        $normalized = [];

        foreach ($customVariables as $key => $value) {
            $normalizedKey = (string) $key;

            if ($normalizedKey === '') {
                continue;
            }

            $stringValue = $this->stringify($value);

            if ($stringValue === null) {
                continue;
            }

            $normalized[$normalizedKey] = $stringValue;
        }

        return $normalized;
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function renderPrompt(string $prompt, array $variables): string
    {
        if ($variables === []) {
            return $prompt;
        }

        return strtr($prompt, $this->replacements($variables));
    }

    /**
     * @param  array<string, scalar|Stringable|null>  $customVariables
     * @return array<string, string>
     */
    private function mergeVariables(PromptVariableContext $context, array $customVariables): array
    {
        $resolved = $this->registry->resolveValues($context);

        return array_merge($resolved, $this->normalizeCustomVariables($customVariables));
    }

    /**
     * @param  array<string, string>  $variables
     * @return array<string, string>
     */
    private function replacements(array $variables): array
    {
        $replacements = [];

        foreach ($variables as $key => $value) {
            $replacements['{'.$key.'}'] = $value;
        }

        return $replacements;
    }

    private function stringify(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value instanceof Stringable) {
            return (string) $value;
        }

        if (is_scalar($value)) {
            return (string) $value;
        }

        return null;
    }
}
