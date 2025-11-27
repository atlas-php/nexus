<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads\Hooks;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Contracts\Container\Container;

/**
 * Class ThreadHookRegistry
 *
 * Resolves and exposes configured thread hooks for orchestrating lifecycle workflows.
 */
class ThreadHookRegistry
{
    /**
     * @var array<string, ThreadHook>
     */
    private array $hooks = [];

    public function __construct(
        private readonly Container $container,
        private readonly ConfigRepository $config
    ) {}

    /**
     * @return array<string, ThreadHook>
     */
    public function all(): array
    {
        $this->warm();

        return $this->hooks;
    }

    public function refresh(): void
    {
        $this->hooks = [];
    }

    private function warm(): void
    {
        if ($this->hooks !== []) {
            return;
        }

        $configured = $this->config->get('atlas-nexus.thread_hooks', []);

        if (! is_array($configured)) {
            return;
        }

        foreach ($configured as $class) {
            if (! is_string($class) || $class === '') {
                continue;
            }

            $hook = $this->container->make($class);

            if (! $hook instanceof ThreadHook) {
                continue;
            }

            $this->hooks[$hook->key()] = $hook;
        }
    }
}
