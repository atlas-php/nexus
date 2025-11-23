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
    private const TIMEOUT_SECONDS = 120;

    public function make(?ChatThreadLog $chatThreadLog = null): TextRequest
    {
        $pendingRequest = Prism::text()->withClientOptions([
            'timeout' => self::TIMEOUT_SECONDS,
        ]);

        return new TextRequest(
            $pendingRequest,
            $chatThreadLog ?? new ChatThreadLog
        );
    }
}
