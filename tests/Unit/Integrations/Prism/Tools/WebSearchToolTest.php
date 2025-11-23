<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Integrations\Prism\Tools;

use Atlas\Nexus\Enums\AiThreadStatus;
use Atlas\Nexus\Enums\AiThreadType;
use Atlas\Nexus\Integrations\Prism\Tools\WebSearchTool;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiAssistantPrompt;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Seeders\NexusSeederService;
use Atlas\Nexus\Services\WebSearch\WebSummaryService;
use Atlas\Nexus\Support\Chat\ThreadState;
use Atlas\Nexus\Tests\TestCase;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Facades\Http;
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Text\Response as TextResponse;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\Usage;

/**
 * Class WebSearchToolTest
 *
 * Covers fetching and summarizing website content through the built-in web search tool.
 */
class WebSearchToolTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadPackageMigrations($this->migrationPath());
        $this->runPendingCommand('migrate:fresh', [
            '--path' => $this->migrationPath(),
            '--realpath' => true,
        ])->run();
    }

    public function test_it_fetches_content_without_summary(): void
    {
        Http::fake([
            'https://example.com' => Http::response('<html><body><h1>Hello</h1><p>World</p></body></html>', 200),
        ]);

        $tool = $this->app->make(WebSearchTool::class);
        $tool->setThreadState($this->createState());

        $response = $tool->handle(['url' => 'https://example.com']);

        $this->assertStringContainsString('Fetched 1 website', $response->message());

        $meta = $response->meta();
        $this->assertArrayHasKey('results', $meta);
        $this->assertCount(1, $meta['results']);
        $this->assertSame('https://example.com', $meta['results'][0]['url']);
        $this->assertNull($meta['results'][0]['error']);
        $this->assertSame('Hello World', (string) $meta['results'][0]['content']);
        $this->assertStringNotContainsString('<h1>', (string) $meta['results'][0]['content']);
    }

    public function test_it_returns_errors_for_invalid_urls(): void
    {
        $tool = $this->app->make(WebSearchTool::class);
        $tool->setThreadState($this->createState());

        $response = $tool->handle(['url' => 'not-a-valid-url']);

        $this->assertStringContainsString('Fetched 1 website', $response->message());

        $results = $response->meta()['results'] ?? [];
        $this->assertCount(1, $results);
        $this->assertNotNull($results[0]['error']);
        $this->assertNull($results[0]['content']);
    }

    public function test_it_summarizes_content_inline(): void
    {
        Http::fake([
            'https://example.com' => Http::response('<html><body><p>This page is used for illustrative examples in documents.</p></body></html>', 200),
        ]);

        /** @var \Illuminate\Support\Collection<int, \Prism\Prism\Contracts\Message> $messages */
        $messages = collect([
            new UserMessage('Summarize the following website content with concise bullet points that capture the key facts.'),
            new AssistantMessage('Example.com is a placeholder site for documentation examples.'),
        ]);

        Prism::fake([
            new TextResponse(
                steps: collect([]),
                text: 'Example.com is a placeholder site for documentation examples.',
                finishReason: FinishReason::Stop,
                toolCalls: [],
                toolResults: [],
                usage: new Usage(5, 5),
                meta: new Meta('summary-1', 'gpt-4o-mini'),
                messages: $messages,
                additionalContent: [],
            ),
        ]);

        $this->app->make(NexusSeederService::class)->run();

        $tool = $this->app->make(WebSearchTool::class);
        $state = $this->createState();
        $tool->setThreadState($state);

        $response = $tool->handle([
            'url' => 'https://example.com',
            'summarize' => true,
        ]);

        $this->assertSame('Example.com is a placeholder site for documentation examples.', $response->message());

        $meta = $response->meta();
        $this->assertSame('Example.com is a placeholder site for documentation examples.', $meta['result']['summary']);
        $this->assertArrayHasKey('summary_thread_id', $meta['result']);

        $summaryThread = AiThread::find($meta['result']['summary_thread_id']);
        $this->assertInstanceOf(AiThread::class, $summaryThread);
        $this->assertTrue($summaryThread->type === AiThreadType::TOOL);
        $this->assertSame($state->thread->id, $summaryThread->parent_thread_id);
        $this->assertSame($state->thread->user_id, $summaryThread->user_id);
        $this->assertSame(AiThreadStatus::OPEN, $summaryThread->status);
    }

    public function test_it_rejects_disallowed_domains(): void
    {
        config()->set('atlas-nexus.tools.options.web_search.allowed_domains', ['example.com']);

        $tool = $this->app->make(WebSearchTool::class);
        $tool->setThreadState($this->createState());

        $response = $tool->handle(['url' => 'https://not-allowed.test/path']);

        $this->assertStringContainsString('Error, this domain is not allowed to be searched', $response->message());

        $meta = $response->meta();
        $this->assertTrue((bool) ($meta['error'] ?? false));
        $this->assertSame('not-allowed.test', $meta['domain']);
        $this->assertSame('https://not-allowed.test/path', $meta['url']);
    }

    public function test_it_includes_allowed_domains_in_description_and_respects_configuration(): void
    {
        config()->set('atlas-nexus.tools.options.web_search.allowed_domains', ['example.com', 'atlasphp.com']);

        Http::fake([
            'https://example.com' => Http::response('<html><body><p>Allowed domain content.</p></body></html>', 200),
        ]);

        $tool = $this->app->make(WebSearchTool::class);

        $this->assertStringContainsString('Allowed domains: example.com, atlasphp.com.', $tool->description());

        $tool->setThreadState($this->createState());

        $response = $tool->handle(['url' => 'https://example.com']);

        $this->assertStringContainsString('Fetched 1 website', $response->message());
    }

    public function test_it_falls_back_when_markdown_converter_is_missing(): void
    {
        Http::fake([
            'https://example.com' => Http::response('<html><body><h1>Hello</h1><p>World</p></body></html>', 200),
        ]);

        $tool = new WebSearchToolWithoutConverter(
            $this->app->make(WebSummaryService::class),
            $this->app->make(ConfigRepository::class)
        );

        $tool->setThreadState($this->createState());

        $response = $tool->handle(['url' => 'https://example.com']);

        $this->assertStringContainsString('Fetched 1 website', $response->message());

        $result = $response->meta()['results'][0];
        $this->assertSame('Hello World', $result['content']);
        $this->assertNull($result['error']);
    }

    public function test_it_respects_text_only_parse_mode(): void
    {
        config()->set('atlas-nexus.tools.options.web_search.parse_mode', 'text_only');

        Http::fake([
            'https://example.com' => Http::response('<html><body><h1>Hello</h1><p>World</p></body></html>', 200),
        ]);

        $tool = $this->app->make(WebSearchTool::class);
        $tool->setThreadState($this->createState());

        $response = $tool->handle(['url' => 'https://example.com']);

        $result = $response->meta()['results'][0];
        $this->assertSame('Hello World', $result['content']);
    }

    public function test_it_respects_null_parse_mode(): void
    {
        config()->set('atlas-nexus.tools.options.web_search.parse_mode', null);

        Http::fake([
            'https://example.com' => Http::response('<html><body><h1>Hello</h1><p>World</p></body></html>', 200),
        ]);

        $tool = $this->app->make(WebSearchTool::class);
        $tool->setThreadState($this->createState());

        $response = $tool->handle(['url' => 'https://example.com']);

        $result = $response->meta()['results'][0];
        $this->assertStringContainsString('<h1>Hello</h1>', $result['content']);
        $this->assertStringContainsString('<p>World</p>', $result['content']);
    }

    public function test_it_strips_scripts_styles_and_head_in_markdown_mode(): void
    {
        Http::fake([
            'https://example.com' => Http::response('<html><head><title>Secret</title></head><body><script>var a=1;</script><style>.a{}</style><h1>Hello</h1><p>World</p></body></html>', 200),
        ]);

        $tool = $this->app->make(WebSearchTool::class);
        $tool->setThreadState($this->createState());

        $response = $tool->handle(['url' => 'https://example.com']);

        $result = $response->meta()['results'][0];
        $this->assertSame('Hello World', $result['content']);
        $this->assertStringNotContainsString('Secret', $result['content']);
        $this->assertStringNotContainsString('var a=1', $result['content']);
    }

    protected function createState(): ThreadState
    {
        $assistant = AiAssistant::factory()->create([
            'slug' => 'web-search',
            'tools' => [WebSearchTool::KEY],
        ]);

        $prompt = AiAssistantPrompt::factory()->create([
            'assistant_id' => $assistant->id,
        ]);

        $assistant->update(['current_prompt_id' => $prompt->id]);

        $thread = AiThread::factory()->create([
            'assistant_id' => $assistant->id,
            'user_id' => 1,
            'type' => AiThreadType::USER->value,
            'status' => AiThreadStatus::OPEN->value,
        ]);

        return new ThreadState(
            $thread,
            $assistant,
            $prompt,
            collect(),
            collect(),
            collect(),
            null,
            null,
            collect()
        );
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../../../database/migrations';
    }
}

/**
 * Class WebSearchToolWithoutConverter
 *
 * Test-only subclass that forces fallback behavior when the Markdown converter is unavailable.
 */
class WebSearchToolWithoutConverter extends WebSearchTool
{
    /**
     * @return class-string
     */
    protected function converterClass(): string
    {
        /** @phpstan-ignore-next-line */
        return 'League\\HTMLToMarkdown\\NonExistentConverter';
    }
}
