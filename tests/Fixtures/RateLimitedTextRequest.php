<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Fixtures;

use Atlas\Nexus\Integrations\Prism\TextRequest;
use Atlas\Nexus\Support\Chat\ChatThreadLog;
use Carbon\Carbon;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response;
use Prism\Prism\ValueObjects\ProviderRateLimit;

/**
 * Text request stub that simulates Prism rate limiting to exercise error messaging.
 */
class RateLimitedTextRequest extends TextRequest
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
        $limits = [
            new ProviderRateLimit(
                'requests_per_minute',
                500,
                0,
                Carbon::parse('2024-01-01 12:00:00', 'UTC')
            ),
        ];

        throw new PrismRateLimitedException($limits, retryAfter: 30);
    }
}
