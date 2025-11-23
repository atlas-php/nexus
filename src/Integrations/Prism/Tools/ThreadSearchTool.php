<?php

declare(strict_types=1);

namespace Atlas\Nexus\Integrations\Prism\Tools;

use Atlas\Nexus\Contracts\ThreadStateAwareTool;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Threads\ThreadManagerService;
use Atlas\Nexus\Support\Chat\ThreadState;
use Atlas\Nexus\Support\Tools\ToolDefinition;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\StringSchema;
use RuntimeException;
use Throwable;

use function collect;
use function is_array;
use function is_int;
use function is_numeric;
use function is_string;
use function trim;

/**
 * Class ThreadSearchTool
 *
 * Searches an assistant's existing user threads so agents can find relevant prior conversations.
 */
class ThreadSearchTool extends AbstractTool implements ThreadStateAwareTool
{
    public const KEY = 'thread_search';

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
        return 'Thread Search';
    }

    public function description(): string
    {
        return 'Search the user\'s threads by title, summaries, keywords, and message content. Use Fetch Thread Content with the thread_ids parameter to inspect a conversation.';
    }

    /**
     * @return array<int, ToolParameter>
     */
    public function parameters(): array
    {
        return [
            new ToolParameter(new NumberSchema('page', 'Optional page number when listing/searching threads.', true), false),
            new ToolParameter(new ArraySchema('search', 'Optional search terms across title, summary, keywords, and message content.', new StringSchema('term', 'Search term', true), true), false),
            new ToolParameter(new ArraySchema('between_dates', 'Optional [start, end] ISO 8601 dates for filtering threads.', new StringSchema('date', 'Date string', true), true, 0, 2), false),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function handle(array $arguments): ToolResponse
    {
        if (! isset($this->state)) {
            return $this->output('Thread search unavailable: missing thread context.', ['error' => true]);
        }

        try {
            $state = $this->state;
            $searchProvided = $this->hasSearchTerms($arguments['search'] ?? null);
            $paginator = $this->threadManagerService->listThreads($state, [
                'page' => $this->normalizeInt($arguments['page'] ?? null),
                'search' => $arguments['search'] ?? null,
                'between_dates' => $arguments['between_dates'] ?? null,
            ]);

            $threads = collect($paginator->items())
                ->map(function (AiThread $thread): array {
                    return [
                        'id' => $thread->id,
                        'title' => $thread->title,
                        'summary' => $thread->summary,
                        'long_summary' => $thread->long_summary,
                        'keywords' => $this->threadManagerService->keywordsForThread($thread),
                    ];
                })
                ->values()
                ->all();

            $message = 'Fetched matching threads.';

            if ($searchProvided && $threads === []) {
                $message = 'No threads matched those keywords. Search checks thread titles, summaries, keywords, message content, and user names. Use Fetch Thread Content with the thread_ids parameter to inspect a specific conversation.';
            }

            return $this->output($message, [
                'result' => [
                    'threads' => $threads,
                    'page' => $paginator->currentPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                ],
            ]);
        } catch (RuntimeException $exception) {
            return $this->output($exception->getMessage(), ['error' => true]);
        } catch (Throwable $exception) {
            return $this->output('Thread search failed: '.$exception->getMessage(), ['error' => true]);
        }
    }

    protected function normalizeInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value;
        }

        return null;
    }

    protected function hasSearchTerms(mixed $value): bool
    {
        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $term) {
            if (is_string($term) && trim($term) !== '') {
                return true;
            }
        }

        return false;
    }
}
