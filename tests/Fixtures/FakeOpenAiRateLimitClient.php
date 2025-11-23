<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Fixtures;

use Atlas\Nexus\Integrations\OpenAI\OpenAiRateLimitClient;
use Atlas\Nexus\Integrations\OpenAI\OpenAiRateLimitSnapshot;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Class FakeOpenAiRateLimitClient
 *
 * Provides deterministic OpenAI limit data for tests without issuing HTTP requests.
 */
class FakeOpenAiRateLimitClient extends OpenAiRateLimitClient
{
    private ?OpenAiRateLimitSnapshot $snapshot = null;

    public function __construct(ConfigRepository $config)
    {
        parent::__construct($config);
    }

    public function setSnapshot(?OpenAiRateLimitSnapshot $snapshot): void
    {
        $this->snapshot = $snapshot;
    }

    public function fetchLimits(?string $group = null): ?OpenAiRateLimitSnapshot
    {
        return $this->snapshot;
    }
}
