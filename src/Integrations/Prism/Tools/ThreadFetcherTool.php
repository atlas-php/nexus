<?php

declare(strict_types=1);

namespace Atlas\Nexus\Integrations\Prism\Tools;

use Atlas\Nexus\Contracts\ThreadStateAwareTool;
use Atlas\Nexus\Models\AiMessage;
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

/**
 * Class ThreadFetcherTool
 *
 * Enables assistants to search and inspect existing threads that belong to the current assistant + user context.
 */
class ThreadFetcherTool extends AbstractTool implements ThreadStateAwareTool
{
    public const KEY = 'thread_fetcher';

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
        return 'Thread Fetcher';
    }

    public function description(): string
    {
        return 'Search threads by title, summaries, keywords, and message content. Use fetch_thread with a thread_id to inspect a conversation.';
    }

    /**
     * @return array<int, ToolParameter>
     */
    public function parameters(): array
    {
        return [
            new ToolParameter(new StringSchema('action', 'Action to perform: search_threads or fetch_thread.', true), true),
            new ToolParameter(new NumberSchema('page', 'Page number when listing/searching threads.', true), false),
            new ToolParameter(new ArraySchema('search', 'Search terms within title, short summary, long summary, keywords, and all message content (search action).', new StringSchema('term', 'Search term', true), true), false),
            new ToolParameter(new ArraySchema('between_dates', 'Optional [start, end] ISO 8601 dates for filtering threads.', new StringSchema('date', 'Date string', true), true, 0, 2), false),
            new ToolParameter(new StringSchema('thread_id', 'Thread identifier for fetch action.', true), false),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function handle(array $arguments): ToolResponse
    {
        if (! isset($this->state)) {
            return $this->output('Thread fetcher unavailable: missing thread context.', ['error' => true]);
        }

        try {
            $state = $this->state;
            $action = $this->normalizeAction($arguments['action'] ?? null);

            return match ($action) {
                'search' => $this->handleSearch($state, $arguments),
                'fetch' => $this->handleFetch($state, $arguments),
                default => $this->output('Provide a valid action: search_threads or fetch_thread.', ['error' => true]),
            };
        } catch (RuntimeException $exception) {
            return $this->output($exception->getMessage(), ['error' => true]);
        } catch (Throwable $exception) {
            return $this->output('Thread fetcher failed: '.$exception->getMessage(), ['error' => true]);
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function handleSearch(ThreadState $state, array $arguments): ToolResponse
    {
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
            $message = 'No threads matched those keywords. Search only checks thread titles, summaries, keywords, and message content—it cannot find user names. Use fetch_thread with a thread_id to inspect a specific conversation.';
        }

        return $this->output($message, [
            'result' => [
                'threads' => $threads,
                'page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function handleFetch(ThreadState $state, array $arguments): ToolResponse
    {
        $threadId = $this->normalizeThreadId($arguments['thread_id'] ?? null);

        if ($threadId === null) {
            throw new RuntimeException('Provide a valid thread_id to fetch its context.');
        }

        $thread = $this->threadManagerService->fetchThread($state, $threadId);
        $keywords = $this->threadManagerService->keywordsForThread($thread);
        $payload = [
            'id' => $thread->id,
            'title' => $thread->title,
            'summary' => $thread->summary,
            'long_summary' => $thread->long_summary,
            'keywords' => $keywords,
            'messages' => $this->mapMessages($thread),
        ];

        return $this->output('Fetched thread context.', [
            'thread_id' => $thread->id,
            'result' => $payload,
        ]);
    }

    protected function normalizeAction(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(str_replace('-', '_', trim($value)));

        return match ($normalized) {
            'search', 'search_threads', 'search_thread', 'list', 'list_threads', 'list_thread' => 'search',
            'fetch', 'fetch_thread', 'show_thread' => 'fetch',
            default => null,
        };
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

    protected function normalizeThreadId(mixed $value): ?int
    {
        $id = $this->normalizeInt($value);

        if ($id === null) {
            return null;
        }

        return $id > 0 ? $id : null;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function mapMessages(AiThread $thread): array
    {
        return $thread->messages
            ->sortBy('sequence')
            ->map(static function (AiMessage $message): array {
                return [
                    'id' => $message->id,
                    'role' => $message->role->value,
                    'content' => $message->content,
                    'sequence' => $message->sequence,
                    'status' => $message->status->value,
                    'created_at' => $message->created_at?->toIso8601String(),
                ];
            })
            ->values()
            ->all();
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
