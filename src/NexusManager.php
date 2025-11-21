<?php

declare(strict_types=1);

namespace Atlas\Nexus;

use Atlas\Nexus\Integrations\Prism\TextRequest;
use Atlas\Nexus\Integrations\Prism\TextRequestFactory;
use Atlas\Nexus\Support\Chat\ChatThreadLog;

/**
 * Class NexusManager
 *
 * Centralizes access to Prism text requests for Nexus consumers.
 * PRD Reference: Atlas Nexus Foundation Setup — Initial manager scaffolding.
 */
class NexusManager
{
    public function __construct(
        private readonly TextRequestFactory $textRequestFactory
    ) {}

    /**
     * Build a Prism text request while preserving direct access to Prism features.
     */
    public function text(?ChatThreadLog $chatThreadLog = null): TextRequest
    {
        return $this->textRequestFactory->make($chatThreadLog);
    }
}
