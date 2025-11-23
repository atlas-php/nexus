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
use Prism\Prism\Schema\StringSchema;
use RuntimeException;
use Throwable;

use function array_column;
use function array_key_first;
use function array_unique;
use function array_values;
use function collect;
use function count;
use function in_array;
use function is_array;
use function is_bool;
use function is_int;
use function is_numeric;
use function is_string;
use function sprintf;
use function strtolower;
use function trim;

/**
 * Class ThreadFetcherTool
 *
 * Fetches one or more threads by id so assistants can inspect summaries and message content.
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
        return 'Fetch Thread Conversations';
    }

    public function description(): string
    {
        return 'Fetch one or more threads by id to view the user\'s conversations';
    }

    /**
     * @return array<int, ToolParameter>
     */
    public function parameters(): array
    {
        return [
            new ToolParameter(
                new ArraySchema(
                    'thread_ids',
                    'Thread ID(s) to fetch (one or many). Provide numeric strings or integers.',
                    new StringSchema('thread_id', 'Thread identifier.', true),
                    true
                ),
                true
            ),
            new ToolParameter(new BooleanSchema('include_assistant', '(optional) Include assistant messages in the output text.', true), false),
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
            $threadIds = $this->resolveThreadIds($arguments);
            $includeAssistant = $this->normalizeBoolean($arguments['include_assistant'] ?? false);
            $threads = $this->threadManagerService->fetchThreads($this->state, $threadIds);

            $payloads = collect($threads)
                ->map(fn (AiThread $thread): array => $this->mapThread($thread))
                ->values()
                ->all();

            if ($payloads === []) {
                throw new RuntimeException('Thread not found for this assistant and user.');
            }

            $message = $this->formatThreadSummaries($payloads, $includeAssistant);

            if (count($payloads) === 1) {
                $thread = $payloads[array_key_first($payloads)];

                return $this->output($message, [
                    'thread_ids' => [$thread['id']],
                    'result' => $thread,
                ]);
            }

            return $this->output($message, [
                'thread_ids' => array_column($payloads, 'id'),
                'result' => [
                    'threads' => $payloads,
                ],
            ]);
        } catch (RuntimeException $exception) {
            return $this->output($exception->getMessage(), ['error' => true]);
        } catch (Throwable $exception) {
            return $this->output('Thread fetcher failed: '.$exception->getMessage(), ['error' => true]);
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<int, int>
     */
    protected function resolveThreadIds(array $arguments): array
    {
        $threadIds = $this->normalizeThreadIds($arguments['thread_ids'] ?? null);

        if ($threadIds === []) {
            throw new RuntimeException('Provide at least one thread_id to fetch.');
        }

        return array_values(array_unique($threadIds));
    }

    protected function normalizeThreadId(mixed $value): ?int
    {
        if (is_int($value)) {
            $id = $value;
        } elseif (is_numeric($value)) {
            $id = (int) $value;
        } else {
            return null;
        }

        return $id > 0 ? $id : null;
    }

    /**
     * @return array<int, int>
     */
    protected function normalizeThreadIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];

        foreach ($value as $id) {
            $normalizedId = $this->normalizeThreadId($id);

            if ($normalizedId !== null) {
                $normalized[] = $normalizedId;
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    protected function mapThread(AiThread $thread): array
    {
        return [
            'id' => $thread->id,
            'title' => $thread->title,
            'summary' => $thread->summary,
            'long_summary' => $thread->long_summary,
            'keywords' => $this->threadManagerService->keywordsForThread($thread),
            'messages' => $this->mapMessages($thread),
        ];
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

    /**
     * @param  array<int, array<string, mixed>>  $threads
     */
    protected function formatThreadSummaries(array $threads, bool $includeAssistant): string
    {
        $blocks = array_map(fn (array $thread): string => $this->formatThreadSummary($thread, $includeAssistant), $threads);

        return implode("\n\n", array_filter($blocks));
    }

    /**
     * @param  array<string, mixed>  $thread
     */
    protected function formatThreadSummary(array $thread, bool $includeAssistant): string
    {
        $lines = [
            sprintf('Thread Id: %s', $thread['id']),
        ];

        $messages = $thread['messages'] ?? [];

        foreach ($messages as $message) {
            $rawRole = $message['role'] ?? 'assistant';
            if (! $includeAssistant && $rawRole === 'assistant') {
                continue;
            }

            $role = $this->formatRole($rawRole);
            $content = trim((string) ($message['content'] ?? ''));
            $lines[] = sprintf('%s: %s', $role, $content === '' ? '[no content]' : $content);
        }

        if (count($lines) === 1 && isset($thread['title'])) {
            $lines[] = 'Title: '.(string) $thread['title'];
        }

        return implode("\n", $lines);
    }

    protected function formatRole(?string $role): string
    {
        return match ($role) {
            'user' => 'User',
            'assistant' => 'Assistant',
            'system' => 'System',
            'tool' => 'Tool',
            default => ucfirst((string) $role),
        };
    }

    protected function normalizeBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_numeric($value)) {
            return (int) $value === 1;
        }

        if (is_string($value)) {
            $normalized = strtolower(trim($value));

            return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
        }

        return false;
    }
}
