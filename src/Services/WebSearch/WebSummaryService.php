<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\WebSearch;

use Atlas\Nexus\Enums\AiMessageContentType;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Enums\AiThreadStatus;
use Atlas\Nexus\Enums\AiThreadType;
use Atlas\Nexus\Models\AiToolRun;
use Atlas\Nexus\Services\Models\AiAssistantService;
use Atlas\Nexus\Services\Models\AiThreadService;
use Atlas\Nexus\Services\Threads\ThreadMessageService;
use Atlas\Nexus\Support\Chat\ThreadState;
use Atlas\Nexus\Support\Web\WebSummaryDefaults;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Class WebSummaryService
 *
 * Runs the built-in web summarizer assistant inline to condense fetched website content.
 */
class WebSummaryService
{
    public function __construct(
        private readonly AiAssistantService $assistantService,
        private readonly AiThreadService $threadService,
        private readonly ThreadMessageService $threadMessageService
    ) {}

    /**
     * @param  array<int, array{url: string, content: string}>  $sources
     * @return array{summary: string, thread: \Atlas\Nexus\Models\AiThread, assistant_message: \Atlas\Nexus\Models\AiMessage}
     */
    public function summarize(ThreadState $state, array $sources, ?AiToolRun $parentRun = null): array
    {
        if ($sources === []) {
            throw new RuntimeException('No website content available to summarize.');
        }

        $assistant = $this->assistantService->query()
            ->where('slug', WebSummaryDefaults::ASSISTANT_SLUG)
            ->first();

        if ($assistant === null) {
            throw new RuntimeException('Web summary assistant has not been seeded.');
        }

        $assistant->loadMissing('currentPrompt');

        if ($assistant->currentPrompt === null) {
            throw new RuntimeException('Web summary assistant is missing an active prompt.');
        }

        $sourceUrls = array_values(array_filter(array_map(
            static fn (array $source): string => (string) $source['url'],
            $sources
        ), static fn (string $url): bool => $url !== ''));

        $thread = $this->threadService->create([
            'assistant_id' => $assistant->id,
            'assistant_prompt_id' => $assistant->current_prompt_id,
            'user_id' => $state->thread->user_id,
            'group_id' => $state->thread->group_id,
            'type' => AiThreadType::TOOL->value,
            'parent_thread_id' => $state->thread->id,
            'parent_tool_run_id' => $parentRun?->id,
            'title' => $this->summaryTitle($sourceUrls),
            'status' => AiThreadStatus::OPEN->value,
            'metadata' => [
                'source_thread_id' => $state->thread->id,
                'source_urls' => $sourceUrls,
            ],
        ]);

        $thread->setRelation('assistant', $assistant);

        $result = $this->threadMessageService->sendUserMessage(
            $thread,
            $this->buildSummaryMessage($sources),
            $state->thread->user_id,
            AiMessageContentType::TEXT,
            false
        );

        $assistantMessage = $result['assistant']->fresh();

        if ($assistantMessage === null || $assistantMessage->status !== AiMessageStatus::COMPLETED) {
            $reason = $assistantMessage !== null
                ? (string) $assistantMessage->failed_reason
                : 'Assistant failed to summarize website content.';

            throw new RuntimeException('Web summarization failed: '.$reason);
        }

        $freshThread = $thread->fresh() ?? $thread;

        return [
            'summary' => $assistantMessage->content,
            'thread' => $freshThread,
            'assistant_message' => $assistantMessage,
        ];
    }

    /**
     * @param  array<int, array{url: string, content: string}>  $sources
     */
    protected function buildSummaryMessage(array $sources): string
    {
        $lines = [
            'Summarize the following website content with concise bullet points that capture the key facts.',
        ];

        foreach ($sources as $index => $source) {
            $url = (string) $source['url'];
            $content = (string) $source['content'];

            $lines[] = sprintf('Source %d: %s', $index + 1, $url !== '' ? $url : 'Unknown URL');
            $lines[] = trim($content);
        }

        return implode("\n\n", $lines);
    }

    /**
     * @param  array<int, string>  $urls
     */
    protected function summaryTitle(array $urls): ?string
    {
        if ($urls === []) {
            return null;
        }

        $first = $urls[0];

        return 'Web summary: '.Str::limit($first, 80, '...');
    }
}
