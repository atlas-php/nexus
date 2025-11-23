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
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\StringSchema;
use RuntimeException;
use Throwable;

use function collect;

/**
 * Class ThreadManagerTool
 *
 * Enables assistants to search, inspect, and summarize user threads within the Nexus workspace.
 */
class ThreadManagerTool extends AbstractTool implements ThreadStateAwareTool
{
    public const KEY = 'thread_manager';

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
        return 'Thread Manager';
    }

    public function description(): string
    {
        return 'Search threads by title/name, summaries, keywords, and message content, then inspect and summarize conversations for the current assistant user.';
    }

    /**
     * @return array<int, ToolParameter>
     */
    public function parameters(): array
    {
        return [
            new ToolParameter(new StringSchema('action', 'Action to perform: search_threads, fetch_thread, update_thread.', true), true),
            new ToolParameter(new NumberSchema('page', 'Page number when listing/searching threads.', true), false),
            new ToolParameter(new ArraySchema('search', 'Search terms applied to title/name, short summary, long summary, keywords, and all message content (search action).', new StringSchema('term', 'Search term', true), true), false),
            new ToolParameter(new ArraySchema('between_dates', 'Optional [start, end] ISO 8601 dates for filtering threads.', new StringSchema('date', 'Date string', true), true, 0, 2), false),
            new ToolParameter(new StringSchema('thread_id', 'Thread identifier for fetch/update actions.', true), false),
            new ToolParameter(new StringSchema('title', 'New thread title (optional).', true), false),
            new ToolParameter(new StringSchema('summary', 'New short summary (optional).', true), false),
            new ToolParameter(new StringSchema('long_summary', 'New long summary (optional).', true), false),
            new ToolParameter(new BooleanSchema('generate_summary', 'Generate thread summaries via assistant.', true), false),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function handle(array $arguments): ToolResponse
    {
        if (! isset($this->state)) {
            return $this->output('Thread manager unavailable: missing thread context.', ['error' => true]);
        }

        try {
            $state = $this->state;
            $action = $this->normalizeAction($arguments['action'] ?? null);

            return match ($action) {
                'search' => $this->handleSearch($state, $arguments),
                'fetch' => $this->handleFetch($state, $arguments),
                'update' => $this->handleUpdate($state, $arguments),
                default => $this->output('Provide a valid action: search_threads, fetch_thread, or update_thread.', ['error' => true]),
            };
        } catch (RuntimeException $exception) {
            return $this->output($exception->getMessage(), ['error' => true]);
        } catch (Throwable $exception) {
            return $this->output('Thread manager failed: '.$exception->getMessage(), ['error' => true]);
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function handleSearch(ThreadState $state, array $arguments): ToolResponse
    {
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

        return $this->output('Fetched matching threads.', [
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

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function handleUpdate(ThreadState $state, array $arguments): ToolResponse
    {
        $threadId = $this->normalizeThreadId($arguments['thread_id'] ?? $state->thread->getKey());

        if ($threadId === null) {
            throw new RuntimeException('Provide a thread_id to update.');
        }

        $title = $this->trimValue($arguments['title'] ?? null);
        $summary = $this->trimValue($arguments['summary'] ?? null);
        $longSummary = $this->trimValue($arguments['long_summary'] ?? null);
        $autoGenerate = $this->shouldAutoGenerate($arguments);

        $result = $this->threadManagerService->updateThread(
            $state,
            $threadId,
            $title,
            $summary,
            $longSummary,
            $autoGenerate
        );

        $message = $autoGenerate
            ? 'Thread title and summaries generated.'
            : 'Thread updated.';

        return $this->output($message, [
            'thread_id' => $result['thread']->id,
            'title' => $result['title'],
            'summary' => $result['summary'],
            'long_summary' => $result['long_summary'],
            'keywords' => $result['keywords'],
            'result' => [
                'title' => $result['title'],
                'summary' => $result['summary'],
                'long_summary' => $result['long_summary'],
                'keywords' => $result['keywords'],
            ],
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
            'update', 'update_thread', 'set_thread' => 'update',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function shouldAutoGenerate(array $arguments): bool
    {
        $flag = $arguments['generate_summary'] ?? false;

        if (is_bool($flag)) {
            return $flag;
        }

        if (is_string($flag)) {
            $normalized = strtolower($flag);

            return in_array($normalized, ['1', 'true', 'yes', 'y', 'auto', 'generate'], true);
        }

        return (bool) $flag;
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

    protected function trimValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
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
}
