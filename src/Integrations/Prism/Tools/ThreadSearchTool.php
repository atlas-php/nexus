<?php

declare(strict_types=1);

namespace Atlas\Nexus\Integrations\Prism\Tools;

use Atlas\Nexus\Contracts\ThreadStateAwareTool;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Threads\ThreadManagerService;
use Atlas\Nexus\Support\Chat\ThreadState;
use Atlas\Nexus\Support\Tools\ToolDefinition;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\StringSchema;
use RuntimeException;
use Throwable;

use function collect;
use function is_array;
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
        return 'Search the user\'s previous thread conversations to find a list of threads.';
    }

    /**
     * @return array<int, ToolParameter>
     */
    public function parameters(): array
    {
        return [
            new ToolParameter(new ArraySchema('search', '(Optional) multiple search queries', new StringSchema('query', 'Search query', true), true), false),
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
            $searchProvided = $this->hasSearchQueries($arguments['search'] ?? null);
            $paginator = $this->threadManagerService->listThreads($state, [
                'search' => $arguments['search'] ?? null,
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
                $message = 'No threads matched those keywords.';
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

    protected function hasSearchQueries(mixed $value): bool
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
