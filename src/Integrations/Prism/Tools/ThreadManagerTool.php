<?php

declare(strict_types=1);

namespace Atlas\Nexus\Integrations\Prism\Tools;

use Atlas\Nexus\Contracts\ThreadStateAwareTool;
use Atlas\Nexus\Services\Threads\ThreadTitleSummaryService;
use Atlas\Nexus\Support\Chat\ThreadState;
use Atlas\Nexus\Support\Tools\ToolDefinition;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\StringSchema;
use RuntimeException;
use Throwable;

/**
 * Class ThreadManagerTool
 *
 * Updates thread title and summary directly or generates them inline using a lightweight model.
 */
class ThreadManagerTool extends AbstractTool implements ThreadStateAwareTool
{
    public const KEY = 'thread_manager';

    protected ?ThreadState $state = null;

    public function __construct(
        private readonly ThreadTitleSummaryService $titleSummaryService
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
        return 'Update or auto-generate the thread title and summary.';
    }

    /**
     * @return array<int, ToolParameter>
     */
    public function parameters(): array
    {
        return [
            new ToolParameter(new StringSchema('title', 'New thread title (optional)', true), false),
            new ToolParameter(new StringSchema('summary', 'New thread summary (optional)', true), false),
            new ToolParameter(new BooleanSchema('create_title_and_summary', 'Generate title and summary from conversation', true), false),
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

        $autoGenerate = $this->shouldAutoGenerate($arguments);
        $title = $this->trimValue($arguments['title'] ?? null);
        $summary = $this->trimValue($arguments['summary'] ?? null);

        try {
            if ($autoGenerate) {
                $result = $this->titleSummaryService->generateAndSave($this->state);

                return $this->output('Thread title and summary generated.', [
                    'thread_id' => $result['thread']->id,
                    'title' => $result['title'],
                    'summary' => $result['summary'],
                    'result' => [
                        'title' => $result['title'],
                        'summary' => $result['summary'],
                    ],
                ]);
            }

            $thread = $this->titleSummaryService->apply($this->state, $title, $summary);

            return $this->output('Thread updated.', [
                'thread_id' => $thread->id,
                'title' => $thread->title,
                'summary' => $thread->summary,
                'result' => [
                    'title' => $thread->title,
                    'summary' => $thread->summary,
                ],
            ]);
        } catch (RuntimeException $exception) {
            return $this->output($exception->getMessage(), ['error' => true]);
        } catch (Throwable $exception) {
            return $this->output('Thread manager failed: '.$exception->getMessage(), ['error' => true]);
        }
    }

    protected function shouldAutoGenerate(array $arguments): bool
    {
        $flag = $arguments['create_title_and_summary'] ?? false;

        if (is_bool($flag)) {
            return $flag;
        }

        if (is_string($flag)) {
            $normalized = strtolower($flag);

            return in_array($normalized, ['1', 'true', 'yes', 'y', 'auto', 'generate'], true);
        }

        return (bool) $flag;
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
