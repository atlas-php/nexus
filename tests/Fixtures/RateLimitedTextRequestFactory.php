<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Fixtures;

use Atlas\Nexus\Integrations\Prism\TextRequest;
use Atlas\Nexus\Integrations\Prism\TextRequestFactory;
use Atlas\Nexus\Services\Threads\Logging\ChatThreadLog;

/**
 * Produces rate-limited text requests for exercising provider rate limit handling.
 */
class RateLimitedTextRequestFactory extends TextRequestFactory
{
    public function make(?ChatThreadLog $chatThreadLog = null): TextRequest
    {
        return new RateLimitedTextRequest($chatThreadLog);
    }
}
