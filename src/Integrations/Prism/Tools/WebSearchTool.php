<?php

declare(strict_types=1);

namespace Atlas\Nexus\Integrations\Prism\Tools;

use Atlas\Nexus\Contracts\ThreadStateAwareTool;
use Atlas\Nexus\Models\AiToolRun;
use Atlas\Nexus\Services\WebSearch\WebSummaryService;
use Atlas\Nexus\Support\Chat\ThreadState;
use Atlas\Nexus\Support\Tools\ToolDefinition;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\StringSchema;
use Throwable;

/**
 * Class WebSearchTool
 *
 * Fetches website content for assistants, normalizes it to Markdown, and optionally summarizes it inline using the built-in web summarizer, with optional allowed-domain restrictions.
 */
class WebSearchTool extends AbstractTool implements ThreadStateAwareTool
{
    public const KEY = 'web_search';

    protected ?ThreadState $state = null;

    protected int $contentLimit;

    /**
     * @var array<int, string>|null
     */
    protected ?array $allowedDomains;

    protected ?string $parseMode = 'markdown';

    protected ?AiToolRun $activeRun = null;

    public function __construct(
        private readonly WebSummaryService $summaryService,
        ConfigRepository $config
    ) {
        $limit = (int) $config->get('atlas-nexus.tools.options.web_search.content_limit', 8000);
        $this->contentLimit = max(500, $limit);
        $this->allowedDomains = $this->normalizeAllowedDomains(
            $config->get('atlas-nexus.tools.options.web_search.allowed_domains')
        );
        $this->parseMode = $this->normalizeParseMode(
            $config->get('atlas-nexus.tools.options.web_search.parse_mode', 'markdown')
        );
    }

    public static function definition(): ToolDefinition
    {
        return new ToolDefinition(self::KEY, self::class);
    }

    public function setThreadState(ThreadState $state): void
    {
        $this->state = $state;
    }

    public function name(): string
    {
        return 'Web Search';
    }

    public function description(): string
    {
        return 'Retrieve website content for context and optionally summarize it. Allowed domains: '.$this->allowedDomainsDescription().'.';
    }

    /**
     * @return array<int, ToolParameter>
     */
    public function parameters(): array
    {
        return [
            new ToolParameter(new StringSchema('url', 'Single website URL to fetch', true), false),
            new ToolParameter(new ArraySchema('urls', 'List of website URLs to fetch', new StringSchema('url', 'Website URL')), false),
            new ToolParameter(new BooleanSchema('summarize', 'Summarize fetched content using the built-in assistant', true), false),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function handle(array $arguments): ToolResponse
    {
        if (! isset($this->state)) {
            return $this->output('Web search tool unavailable: missing thread context.', ['error' => true]);
        }

        $urls = $this->collectUrls($arguments);

        if ($urls === []) {
            return $this->output('Provide at least one website URL to fetch.', ['error' => true]);
        }

        $domainRestriction = $this->restrictToAllowedDomains($urls);

        if ($domainRestriction instanceof ToolResponse) {
            return $domainRestriction;
        }

        /** @var array<int, array{url: string, status: int|null, content: string|null, error: string|null}> $results */
        $results = array_map(fn (string $url): array => $this->fetchFromUrl($url), $urls);
        $successful = array_values(array_filter(
            $results,
            static fn (array $result): bool => $result['error'] === null && is_string($result['content']) && $result['content'] !== ''
        ));

        if ($this->shouldSummarize($arguments) && $successful !== []) {
            try {
                $summaryResult = $this->summaryService->summarize(
                    $this->state,
                    $this->summarizationSources($successful),
                    $this->activeRun
                );
            } catch (Throwable $exception) {
                return $this->output(
                    'Failed to summarize website content: '.$exception->getMessage(),
                    [
                        'error' => true,
                        'results' => $results,
                        'result' => [
                            'error' => $exception->getMessage(),
                            'results' => $results,
                        ],
                    ]
                );
            }

            return $this->output(
                $summaryResult['summary'],
                [
                    'results' => $results,
                    'result' => [
                        'summary' => $summaryResult['summary'],
                        'summary_thread_id' => $summaryResult['thread']->id,
                        'summary_message_id' => $summaryResult['assistant_message']->id,
                        'sources' => $successful,
                    ],
                ]
            );
        }

        return $this->output(
            $this->buildContentMessage($results),
            [
                'results' => $results,
                'result' => $results,
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<int, string>
     */
    protected function collectUrls(array $arguments): array
    {
        $urls = [];

        $single = (string) ($arguments['url'] ?? '');

        if ($single !== '') {
            $urls[] = $single;
        }

        $list = $arguments['urls'] ?? null;

        if (is_array($list)) {
            foreach ($list as $value) {
                $url = (string) $value;

                if ($url !== '') {
                    $urls[] = $url;
                }
            }
        }

        return array_values(array_unique(array_map('trim', $urls)));
    }

    /**
     * @return array{url: string, status: int|null, content: string|null, error: string|null}
     */
    protected function fetchFromUrl(string $url): array
    {
        $url = trim($url);

        if ($url === '' || filter_var($url, FILTER_VALIDATE_URL) === false) {
            return [
                'url' => $url,
                'status' => null,
                'content' => null,
                'error' => 'Invalid URL provided.',
            ];
        }

        try {
            $response = Http::timeout(10)->get($url);
        } catch (Throwable $exception) {
            return [
                'url' => $url,
                'status' => null,
                'content' => null,
                'error' => $exception->getMessage(),
            ];
        }

        if (! $response->successful()) {
            return [
                'url' => $url,
                'status' => $response->status(),
                'content' => null,
                'error' => 'Request failed with status '.$response->status(),
            ];
        }

        $normalized = $this->normalizeContent((string) $response->body());

        if ($normalized === '') {
            return [
                'url' => $url,
                'status' => $response->status(),
                'content' => null,
                'error' => 'No readable content found for this page.',
            ];
        }

        return [
            'url' => $url,
            'status' => $response->status(),
            'content' => $normalized,
            'error' => null,
        ];
    }

    protected function normalizeContent(string $body): string
    {
        if ($this->parseMode === null) {
            $trimmed = trim($body);
        } elseif ($this->parseMode === 'text_only') {
            $text = $this->plainTextFallback($body);
            $normalizedLineBreaks = preg_replace("/\r\n?/", "\n", $text) ?? '';
            $deduplicatedSpacing = preg_replace("/\n{3,}/", "\n\n", $normalizedLineBreaks) ?? '';
            $trimmed = trim($deduplicatedSpacing);
        } else {
            $markdown = $this->convertToMarkdown($body);
            $normalizedLineBreaks = preg_replace("/\r\n?/", "\n", $markdown) ?? '';
            $deduplicatedSpacing = preg_replace("/\n{3,}/", "\n\n", $normalizedLineBreaks) ?? '';
            $trimmed = trim($deduplicatedSpacing);
        }

        if ($trimmed === '') {
            return '';
        }

        return Str::limit($trimmed, $this->contentLimit, '...');
    }

    /**
     * @param  array<int, array{url: string, status: int|null, content: string|null, error: string|null}>  $results
     */
    /**
     * @param  array<int, array{url: string, status: int|null, content: string|null, error: string|null}>  $results
     */
    protected function buildContentMessage(array $results): string
    {
        if ($results === []) {
            return 'No URLs were fetched.';
        }

        $lines = ['Fetched '.count($results).' website(s):'];

        foreach ($results as $result) {
            $url = $result['url'];

            if ($result['error'] !== null) {
                $lines[] = sprintf('- %s (error: %s)', $url, $result['error']);

                continue;
            }

            $snippet = Str::limit((string) $result['content'], 500, '...');
            $lines[] = sprintf('- %s: %s', $url, $snippet);
        }

        return implode("\n", $lines);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function shouldSummarize(array $arguments): bool
    {
        $flag = $arguments['summarize'] ?? $arguments['summerize'] ?? false;

        if (is_bool($flag)) {
            return $flag;
        }

        if (is_string($flag)) {
            $normalized = strtolower($flag);

            return in_array($normalized, ['1', 'true', 'yes', 'y', 'summarize'], true);
        }

        return (bool) $flag;
    }

    /**
     * @param  array<int, array{url: string, status: int|null, content: string|null, error: string|null}>  $results
     * @return array<int, array{url: string, content: string}>
     */
    /**
     * @param  array<int, array{url: string, status: int|null, content: string|null, error: string|null}>  $results
     * @return array<int, array{url: string, content: string}>
     */
    protected function summarizationSources(array $results): array
    {
        return array_map(
            static fn (array $result): array => [
                'url' => $result['url'],
                'content' => (string) $result['content'],
            ],
            $results
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function logRunStart(array $arguments): ?AiToolRun
    {
        $run = parent::logRunStart($arguments);
        $this->activeRun = $run;

        return $run;
    }

    /**
     * @param  array<int, string>  $urls
     */
    protected function restrictToAllowedDomains(array $urls): ?ToolResponse
    {
        if ($this->allowedDomains === null) {
            return null;
        }

        foreach ($urls as $url) {
            $host = strtolower((string) parse_url($url, PHP_URL_HOST));
            $normalizedHost = ltrim($host, '.');

            if ($normalizedHost === '') {
                continue;
            }

            if ($this->isAllowedDomain($normalizedHost)) {
                continue;
            }

            $message = sprintf('Error, this domain is not allowed to be searched: %s', $normalizedHost);

            return $this->output($message, [
                'error' => true,
                'url' => $url,
                'domain' => $normalizedHost,
            ]);
        }

        return null;
    }

    protected function isAllowedDomain(string $host): bool
    {
        if ($this->allowedDomains === null) {
            return true;
        }

        foreach ($this->allowedDomains as $allowedDomain) {
            if ($host === $allowedDomain || Str::endsWith($host, '.'.$allowedDomain)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string>|string|null  $configured
     * @return array<int, string>|null
     */
    protected function normalizeAllowedDomains(array|string|null $configured): ?array
    {
        if ($configured === null) {
            return null;
        }

        if (is_string($configured)) {
            $configured = explode(',', $configured);
        }

        $domains = array_values(array_filter(array_map(
            static function (mixed $domain): ?string {
                $value = strtolower(trim((string) $domain));
                $trimmed = ltrim($value, '.');

                return $trimmed !== '' ? $trimmed : null;
            },
            $configured
        )));

        return $domains;
    }

    protected function allowedDomainsDescription(): string
    {
        if ($this->allowedDomains === null) {
            return 'any domain';
        }

        if ($this->allowedDomains === []) {
            return 'none';
        }

        return implode(', ', $this->allowedDomains);
    }

    protected function normalizeParseMode(mixed $parseMode): ?string
    {
        if ($parseMode === null) {
            return null;
        }

        $value = strtolower((string) $parseMode);

        return in_array($value, ['markdown', 'text_only'], true) ? $value : 'markdown';
    }

    /**
     * Convert HTML to Markdown, falling back to stripped text if the converter dependency is unavailable.
     */
    protected function convertToMarkdown(string $body): string
    {
        $converterClass = $this->converterClass();

        if (! class_exists($converterClass)) {
            return $this->plainTextFallback($body);
        }

        /** @var \League\HTMLToMarkdown\HtmlConverter|null $instance */
        $instance = $this->markdownConverter ?? null;

        if ($instance !== null) {
            return $instance->convert($this->stripScriptsStylesAndHead($body));
        }

        /** @var \League\HTMLToMarkdown\HtmlConverter $instance */
        $instance = new $converterClass($this->markdownConverterOptions());

        $this->markdownConverter = $instance;

        return $instance->convert($this->stripScriptsStylesAndHead($body));
    }

    /**
     * @var \League\HTMLToMarkdown\HtmlConverter|null
     */
    private $markdownConverter = null;

    /**
     * Converter options pulled directly from the upstream library.
     *
     * @return array<string, mixed>
     */
    protected function markdownConverterOptions(): array
    {
        return [
            'strip_tags' => true,
            'remove_nodes' => 'script style',
        ];
    }

    /**
     * @return class-string
     */
    protected function converterClass(): string
    {
        return \League\HTMLToMarkdown\HtmlConverter::class;
    }

    protected function stripScriptsStylesAndHead(string $body): string
    {
        $withoutScripts = preg_replace('#<script\b[^>]*>.*?</script>#is', ' ', $body) ?? $body;
        $withoutStyles = preg_replace('#<style\b[^>]*>.*?</style>#is', ' ', $withoutScripts) ?? $withoutScripts;

        return preg_replace('#<head\b[^>]*>.*?</head>#is', ' ', $withoutStyles) ?? $withoutStyles;
    }

    protected function plainTextFallback(string $body): string
    {
        $decoded = html_entity_decode($body, ENT_QUOTES | ENT_HTML5);
        $sanitized = $this->stripScriptsStylesAndHead($decoded);
        $withoutTags = preg_replace('/<[^>]+>/', ' ', $sanitized) ?? $sanitized;
        $stripped = strip_tags($withoutTags);
        $singleSpaced = preg_replace('/\s+/', ' ', $stripped) ?? '';

        return trim($singleSpaced);
    }
}
