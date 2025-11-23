<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Integrations\OpenAI;

use Atlas\Nexus\Integrations\OpenAI\OpenAiRateLimitClient;
use Atlas\Nexus\Tests\TestCase;
use Illuminate\Support\Facades\Http;

/**
 * Class OpenAiRateLimitClientTest
 *
 * Validates communication with the OpenAI limits API and the normalization helpers used for failure context.
 */
class OpenAiRateLimitClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();

        config()->set('prism.providers.openai.url', 'https://api.openai.com/v1');
        config()->set('prism.providers.openai.api_key', 'test-key');
        config()->set('prism.providers.openai.organization', 'test-org');
        config()->set('prism.providers.openai.project', 'proj_limits');
    }

    public function test_it_returns_null_when_api_key_is_missing(): void
    {
        config()->set('prism.providers.openai.api_key', null);

        Http::fake();

        $client = $this->app->make(OpenAiRateLimitClient::class);

        $this->assertNull($client->fetchLimits());
        Http::assertNothingSent();
    }

    public function test_it_fetches_and_normalizes_limits(): void
    {
        Http::fake([
            'https://api.openai.com/v1/organization/limits*' => Http::response([
                'data' => [
                    [
                        'name' => 'requests_per_minute',
                        'limit' => 1000,
                        'usage' => 900,
                        'remaining' => 100,
                        'resets_at' => '2024-05-01T00:00:00Z',
                        'status' => 'active',
                        'window' => '1m',
                    ],
                    [
                        'name' => 'tokens_per_day',
                        'limit' => 100000,
                        'usage' => 50000,
                        'window' => '1d',
                        'scope' => 'gpt-4o-mini',
                    ],
                ],
            ]),
        ]);

        $client = $this->app->make(OpenAiRateLimitClient::class);
        $snapshot = $client->fetchLimits();

        $this->assertNotNull($snapshot);
        $this->assertCount(2, $snapshot->limits);
        $this->assertSame('[requests_per_minute(limit=1000, usage=900, remaining=100, resets_at=2024-05-01T00:00:00+00:00, window=1m, status=active), tokens_per_day(limit=100000, usage=50000, remaining=50000, resets_at=unknown, window=1d, scope=gpt-4o-mini)]', $snapshot->describe());

        Http::assertSent(function ($request) {
            $url = (string) $request->url();

            return $request->hasHeader('Authorization', 'Bearer test-key')
                && $request->hasHeader('OpenAI-Organization', 'test-org')
                && str_contains($url, '/organization/limits')
                && str_contains($url, 'group=proj_limits');
        });
    }

    public function test_it_returns_null_when_request_fails(): void
    {
        Http::fake([
            'https://api.openai.com/v1/organization/limits*' => Http::response(['error' => 'bad'], 500),
        ]);

        $client = $this->app->make(OpenAiRateLimitClient::class);

        $this->assertNull($client->fetchLimits());
    }
}
