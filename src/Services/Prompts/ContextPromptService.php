<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Prompts;

use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Support\Assistants\ResolvedAssistant;
use Atlas\Nexus\Support\Prompts\ContextPrompt;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Foundation\Application;

/**
 * Class ContextPromptService
 *
 * Resolves the configured context prompt builder and renders its content for a given thread.
 */
class ContextPromptService
{
    private bool $resolved = false;

    private ?ContextPrompt $contextPrompt = null;

    public function __construct(
        private readonly Application $app,
        private readonly ConfigRepository $config
    ) {}

    public function buildForThread(AiThread $thread, ResolvedAssistant $assistant): ?string
    {
        $prompt = $this->prompt();

        if ($prompt === null) {
            return null;
        }

        return $prompt->compose($thread, $assistant);
    }

    private function prompt(): ?ContextPrompt
    {
        if ($this->resolved) {
            return $this->contextPrompt;
        }

        $this->resolved = true;
        $class = $this->config->get('atlas-nexus.context_prompt');

        if (! is_string($class) || $class === '') {
            return null;
        }

        $instance = $this->app->make($class);

        if (! $instance instanceof ContextPrompt) {
            return null;
        }

        $this->contextPrompt = $instance;

        return $this->contextPrompt;
    }
}
