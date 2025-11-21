<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Prompts;

use Atlas\Nexus\Support\Prompts\PromptVariableContext;
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
        $resolved = $this->registry->resolveValues($context);
        $merged = array_merge($resolved, $this->normalizeCustomVariables($customVariables));

        if ($merged === []) {
            return $prompt;
        }

        $replacements = [];

        foreach ($merged as $key => $value) {
            $replacements['{'.$key.'}'] = $value;
        }

        return strtr($prompt, $replacements);
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
