<?php

declare(strict_types=1);

namespace Atlas\Nexus\Integrations\Prism;

use Atlas\Nexus\Support\Chat\ChatThreadLog;
use Prism\Prism\Facades\Prism;

/**
 * Class TextRequestFactory
 *
 * Produces Prism text requests wrapped with Nexus chat logging support.
 */
class TextRequestFactory
{
    public function make(?ChatThreadLog $chatThreadLog = null): TextRequest
    {
        return new TextRequest(
            Prism::text(),
            $chatThreadLog ?? new ChatThreadLog
        );
    }
}
