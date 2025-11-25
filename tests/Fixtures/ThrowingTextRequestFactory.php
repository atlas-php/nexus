<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Fixtures;

use Atlas\Nexus\Integrations\Prism\TextRequest;
use Atlas\Nexus\Integrations\Prism\TextRequestFactory;
use Atlas\Nexus\Services\Threads\Logging\ChatThreadLog;

/**
 * Creates throwing text requests so Prism generation errors can be exercised in tests.
 */
class ThrowingTextRequestFactory extends TextRequestFactory
{
    public function make(?ChatThreadLog $chatThreadLog = null): TextRequest
    {
        return new ThrowingTextRequest($chatThreadLog);
    }
}
