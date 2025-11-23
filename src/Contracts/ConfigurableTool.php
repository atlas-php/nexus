<?php

declare(strict_types=1);

namespace Atlas\Nexus\Contracts;

/**
 * Interface ConfigurableTool
 *
 * Allows Nexus tools to receive assistant-level configuration arrays before they are converted into Prism tools.
 */
interface ConfigurableTool
{
    /**
     * @param  array<string, mixed>  $configuration
     */
    public function applyConfiguration(array $configuration): void;
}
