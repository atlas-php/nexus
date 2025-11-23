<?php

declare(strict_types=1);

namespace Atlas\Nexus\Integrations\Prism\Tools;

use Atlas\Nexus\Contracts\ThreadStateAwareTool;
use Atlas\Nexus\Services\Threads\ThreadManagerService;
use Atlas\Nexus\Support\Chat\ThreadState;
use Atlas\Nexus\Support\Tools\ToolDefinition;
use Prism\Prism\Schema\StringSchema;
use RuntimeException;
use Throwable;

/**
 * Class ThreadUpdaterTool
 *
 * Enables assistants to update thread titles and summaries or trigger automatic generation via the summary assistant.
 */
class ThreadUpdaterTool extends AbstractTool implements ThreadStateAwareTool
{
    public const KEY = 'thread_updater';

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
        return 'Thread Updater';
    }

    public function description(): string
    {
        return 'Update the current thread title, short summary, and long summary or request auto-generated summaries using the built-in assistant.';
    }

    /**
     * @return array<int, ToolParameter>
     */
    public function parameters(): array
    {
        return [
            new ToolParameter(new StringSchema('action', 'Action to perform: update_thread or generate_summary.', true), true),
            new ToolParameter(new StringSchema('thread_id', 'Thread identifier. Defaults to the active thread.', true), false),
            new ToolParameter(new StringSchema('title', 'New thread title (optional).', true), false),
            new ToolParameter(new StringSchema('summary', 'New short summary (optional).', true), false),
            new ToolParameter(new StringSchema('long_summary', 'New long summary (optional).', true), false),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function handle(array $arguments): ToolResponse
    {
        if (! isset($this->state)) {
            return $this->output('Thread updater unavailable: missing thread context.', ['error' => true]);
        }

        try {
            $state = $this->state;
            $action = $this->normalizeAction($arguments['action'] ?? null);

            if ($action === null) {
                throw new RuntimeException('Provide a valid action: update_thread or generate_summary.');
            }

            $threadId = $this->normalizeThreadId($arguments['thread_id'] ?? $state->thread->getKey());

            if ($threadId === null) {
                throw new RuntimeException('Provide a thread_id to update.');
            }

            $title = $this->trimValue($arguments['title'] ?? null);
            $summary = $this->trimValue($arguments['summary'] ?? null);
            $longSummary = $this->trimValue($arguments['long_summary'] ?? null);
            $autoGenerate = $action === 'generate';

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
        } catch (RuntimeException $exception) {
            return $this->output($exception->getMessage(), ['error' => true]);
        } catch (Throwable $exception) {
            return $this->output('Thread updater failed: '.$exception->getMessage(), ['error' => true]);
        }
    }

    protected function normalizeAction(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(str_replace('-', '_', trim($value)));

        return match ($normalized) {
            'update', 'update_thread', 'set_thread' => 'update',
            'generate', 'generate_summary', 'auto_generate' => 'generate',
            default => null,
        };
    }

    protected function normalizeThreadId(mixed $value): ?int
    {
        $id = $this->normalizeInt($value);

        if ($id === null) {
            return null;
        }

        return $id > 0 ? $id : null;
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

    protected function trimValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
