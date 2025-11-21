<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Fixtures;

use Atlas\Nexus\Integrations\Prism\TextRequest;
use Atlas\Nexus\Support\Chat\ChatThreadLog;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response;
use RuntimeException;

/**
 * Text request stub that simulates a Prism failure for testing job error handling.
 */
class ThrowingTextRequest extends TextRequest
{
    public function __construct(?ChatThreadLog $chatThreadLog = null)
    {
        parent::__construct(
            Prism::text(),
            $chatThreadLog ?? new ChatThreadLog
        );
    }

    public function asText(): ?Response
    {
        throw new RuntimeException('Simulated Prism failure');
    }
}
