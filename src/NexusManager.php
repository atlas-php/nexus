<?php

declare(strict_types=1);

namespace Atlas\Nexus;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use InvalidArgumentException;

/**
 * Class NexusManager
 *
 * Centralizes access to Nexus pipeline configuration to coordinate future AI flows.
 * PRD Reference: Atlas Nexus Foundation Setup — Initial manager scaffolding.
 */
class NexusManager
{
    public function __construct(
        private readonly ConfigRepository $config
    ) {}

    /**
     * Retrieve the declared configuration for a named pipeline.
     *
     * @return array<string, mixed>
     */
    public function getPipelineConfig(string $pipeline): array
    {
        $pipelines = $this->config->get('atlas-nexus.pipelines', []);

        if (! array_key_exists($pipeline, $pipelines)) {
            throw new InvalidArgumentException(sprintf('Pipeline [%s] is not defined.', $pipeline));
        }

        $pipelineConfig = $pipelines[$pipeline];

        if (! is_array($pipelineConfig)) {
            throw new InvalidArgumentException(sprintf('Pipeline [%s] configuration must be an array.', $pipeline));
        }

        return $pipelineConfig;
    }

    /**
     * Determine which pipeline should be considered the default orchestration target.
     */
    public function getDefaultPipelineName(): ?string
    {
        $default = $this->config->get('atlas-nexus.default_pipeline');

        return is_string($default) && $default !== '' ? $default : null;
    }
}
