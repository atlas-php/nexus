<?php

declare(strict_types=1);

namespace Atlas\Nexus\Integrations\Prism\Tools;

use Atlas\Nexus\Contracts\ThreadStateAwareTool;
use Atlas\Nexus\Services\Threads\Data\ThreadState;
use Atlas\Nexus\Services\Threads\ThreadManagerService;
use Atlas\Nexus\Services\Tools\ToolDefinition;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\StringSchema;
use RuntimeException;
use Throwable;

use function implode;
use function is_array;
use function is_string;
use function trim;

/**
 * Class FetchMoreContextTool
 *
 * Provides paginated thread context so assistants can reference prior conversations without switching threads.
 */
class FetchMoreContextTool extends AbstractTool implements ThreadStateAwareTool
{
    public const KEY = 'fetch_more_context';

    protected ?ThreadState $state = null;

    public function __construct(
        private readonly ThreadManagerService $threadManagerService
    ) {}

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
        return 'Fetch More Context';
    }

    public function description(): string
    {
        return 'Search existing threads (title, summary, keywords, memories, and message content) to gather up to 10 summaries for additional context.';
    }

    /**
     * @return array<int, ToolParameter>
     */
    public function parameters(): array
    {
        return [
            new ToolParameter(
                new ArraySchema(
                    'search',
                    '(Optional) One or more search queries to filter threads.',
                    new StringSchema('query', 'Search query.', true),
                    true
                ),
                false
            ),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function handle(array $arguments): ToolResponse
    {
        if (! isset($this->state)) {
            return $this->output('FetchMoreContext unavailable: missing thread context.', ['error' => true]);
        }

        try {
            $state = $this->state;
            $searchTerms = $arguments['search'] ?? null;
            $threads = $this->threadManagerService->fetchContextSummaries($state, $searchTerms);

            $message = $threads === []
                ? 'No threads matched those queries.'
                : $this->formatThreadSummaries($threads);

            return $this->output($message, [
                'result' => [
                    'threads' => $threads,
                ],
            ]);
        } catch (RuntimeException $exception) {
            return $this->output($exception->getMessage(), ['error' => true]);
        } catch (Throwable $exception) {
            return $this->output('FetchMoreContext failed: '.$exception->getMessage(), ['error' => true]);
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $threads
     */
    protected function formatThreadSummaries(array $threads): string
    {
        $blocks = [];

        foreach ($threads as $thread) {
            $lines = [];

            $title = $this->stringValue($thread['title'] ?? null);
            $summary = $this->stringValue($thread['summary'] ?? null);

            if ($title !== null) {
                $lines[] = 'Title: '.$title;
            }

            if ($summary !== null) {
                $lines[] = 'Summary: '.$summary;
            }

            if ($lines === []) {
                $lines[] = 'Summary: None available.';
            }

            $memories = $thread['memories'] ?? [];

            if (is_array($memories) && $memories !== []) {
                $lines[] = 'Memories:';

                foreach ($memories as $memory) {
                    $lines[] = '- '.$memory;
                }
            }

            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks);
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
