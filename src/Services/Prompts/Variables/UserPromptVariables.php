<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Prompts\Variables;

use Atlas\Nexus\Contracts\PromptVariableGroup;
use Atlas\Nexus\Services\Prompts\PromptVariableContext;

/**
 * Class UserPromptVariables
 *
 * Resolves multiple user fields in a single provider for prompt interpolation.
 */
class UserPromptVariables implements PromptVariableGroup
{
    /**
     * @return array<string, string|null>
     */
    public function variables(PromptVariableContext $context): array
    {
        $user = $context->user();

        if ($user === null) {
            return [];
        }

        $name = $user->getAttribute('name');
        $email = $user->getAttribute('email');

        return [
            'USER.NAME' => is_string($name) && $name !== '' ? $name : null,
            'USER.EMAIL' => is_string($email) && $email !== '' ? $email : null,
        ];
    }
}
