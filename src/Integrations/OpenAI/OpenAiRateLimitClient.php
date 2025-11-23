<?php

declare(strict_types=1);

namespace Atlas\Nexus\Integrations\OpenAI;

use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Class OpenAiRateLimitClient
 *
 * Provides a light-weight client for the OpenAI limits API so Nexus can surface current account usage when failures occur.
 */
class OpenAiRateLimitClient
{
    public function __construct(private readonly ConfigRepository $config) {}

    public function fetchLimits(?string $group = null): ?OpenAiRateLimitSnapshot
    {
        $request = $this->buildRequest();

        if ($request === null) {
            return null;
        }

        $query = $this->buildQuery($group);

        try {
            $response = $request->get('organization/limits', $query);
        } catch (Throwable) {
            return null;
        }

        if (! $response->successful()) {
            return null;
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            return null;
        }

        return new OpenAiRateLimitSnapshot(
            $this->extractLimits($payload),
            $payload
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, OpenAiRateLimit>
     */
    protected function extractLimits(array $payload): array
    {
        $limits = [];
        $candidates = $this->candidateArrays($payload);

        if ($candidates === []) {
            $this->collectLimits($payload, $limits);

            return array_values($limits);
        }

        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }

            $this->collectLimits($candidate, $limits);
        }

        return array_values($limits);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<int, mixed>
     */
    protected function candidateArrays(array $payload): array
    {
        $keys = ['limits', 'data', 'items', 'grants'];
        $candidates = [];

        foreach ($keys as $key) {
            $value = $payload[$key] ?? null;

            if (is_array($value)) {
                $candidates[] = $value;
            }
        }

        return $candidates;
    }

    /**
     * @param  array<int|string, mixed>  $data
     * @param  array<string, OpenAiRateLimit>  $limits
     */
    protected function collectLimits(array $data, array &$limits): void
    {
        $limit = OpenAiRateLimit::fromArray($data);

        if ($limit instanceof OpenAiRateLimit) {
            $key = $limit->name.($limit->scope !== null ? '@'.$limit->scope : '');
            $limits[$key] = $limit;
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $this->collectLimits($value, $limits);
            }
        }
    }

    protected function buildRequest(): ?PendingRequest
    {
        $apiKey = $this->apiKey();

        if ($apiKey === null) {
            return null;
        }

        $request = Http::baseUrl($this->baseUrl())
            ->acceptJson()
            ->withToken($apiKey)
            ->timeout($this->timeout());

        $organization = $this->organization();

        if ($organization !== null) {
            $request = $request->withHeaders(['OpenAI-Organization' => $organization]);
        }

        return $request;
    }

    /**
     * @return array<string, string>
     */
    protected function buildQuery(?string $group): array
    {
        $group = $group ?? $this->defaultGroup();

        return is_string($group) && $group !== ''
            ? ['group' => $group]
            : [];
    }

    protected function baseUrl(): string
    {
        $default = 'https://api.openai.com/v1';
        $configured = $this->config->get('prism.providers.openai.url');

        $baseUrl = is_string($configured) && $configured !== '' ? $configured : $default;

        return rtrim($baseUrl, '/').'/';
    }

    protected function timeout(): float
    {
        $timeout = $this->config->get('prism.providers.openai.timeout');

        if (is_numeric($timeout)) {
            return max(1.0, (float) $timeout);
        }

        return 8.0;
    }

    protected function defaultGroup(): ?string
    {
        $group = $this->config->get('prism.providers.openai.project');

        return is_string($group) && $group !== '' ? $group : null;
    }

    protected function apiKey(): ?string
    {
        $key = $this->config->get('prism.providers.openai.api_key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    protected function organization(): ?string
    {
        $organization = $this->config->get('prism.providers.openai.organization');

        return is_string($organization) && $organization !== '' ? $organization : null;
    }
}
