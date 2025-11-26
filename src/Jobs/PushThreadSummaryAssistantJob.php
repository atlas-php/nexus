<?php

declare(strict_types=1);

namespace Atlas\Nexus\Jobs;

use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Integrations\Prism\TextRequestFactory;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Assistants\AssistantRegistry;
use Atlas\Nexus\Services\Assistants\ResolvedAssistant;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Models\AiThreadService;
use Atlas\Nexus\Services\Prompts\PromptVariableContext;
use Atlas\Nexus\Services\Prompts\PromptVariableService;
use Atlas\Nexus\Services\Threads\AssistantThreadLogger;
use Atlas\Nexus\Services\Threads\Data\ThreadState;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use RuntimeException;

/**
 * Class PushThreadSummaryAssistantJob
 *
 * Dispatches the hidden thread summary assistant whenever a thread needs refreshed metadata.
 */
class PushThreadSummaryAssistantJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    private const THREAD_SUMMARY_ASSISTANT_KEY = 'thread-summary-assistant';

    public int $tries = 1;

    public int $timeout = 120;

    public function __construct(public int $threadId)
    {
        $queue = $this->resolveQueue();

        if ($queue !== null) {
            $this->onQueue($queue);
        }
    }

    public function handle(
        AiThreadService $threadService,
        AssistantRegistry $assistantRegistry,
        AiMessageService $messageService,
        TextRequestFactory $textRequestFactory,
        PromptVariableService $promptVariableService,
        AssistantThreadLogger $assistantThreadLogger
    ): void {
        $thread = $threadService->find($this->threadId);

        if ($thread === null) {
            return;
        }

        $assistant = $assistantRegistry->require(self::THREAD_SUMMARY_ASSISTANT_KEY);

        $messages = $messageService->query()
            ->where('thread_id', $thread->id)
            ->where('status', AiMessageStatus::COMPLETED->value)
            ->where('is_context_prompt', false)
            ->orderBy('sequence')
            ->get();

        if ($messages->isEmpty()) {
            return;
        }

        $state = new ThreadState(
            $thread,
            $assistant,
            $assistant->systemPrompt(),
            $messages,
            collect(),
            collect(),
            null,
            collect()
        );

        $summary = $this->generateSummary(
            $state,
            $assistant,
            $textRequestFactory,
            $promptVariableService,
            $assistantThreadLogger
        );

        /** @var AiMessage $lastMessage */
        $lastMessage = $messages->last();

        $metadata = array_merge($thread->metadata ?? [], [
            'keywords' => $summary['keywords'],
        ]);

        $payload = [
            'summary' => $summary['summary'],
            'metadata' => $metadata,
            'last_summary_message_id' => $lastMessage->getKey(),
        ];

        if ($thread->title === null) {
            $payload['title'] = $summary['title'];
        }

        $threadService->update($thread, $payload);
    }

    /**
     * @return array{title: string, summary: string, keywords: array<int, string>}
     */
    protected function generateSummary(
        ThreadState $state,
        ResolvedAssistant $assistant,
        TextRequestFactory $textRequestFactory,
        PromptVariableService $promptVariableService,
        AssistantThreadLogger $assistantThreadLogger
    ): array {
        if ($state->messages->isEmpty()) {
            throw new RuntimeException('Cannot generate title and summary without conversation messages.');
        }

        $prompt = $assistant->systemPrompt();

        if ($prompt === '') {
            throw new RuntimeException('Thread summary assistant prompt is missing.');
        }

        $promptContext = new PromptVariableContext($state, $assistant, $prompt);
        $promptText = $promptVariableService->apply($prompt, $promptContext);
        $conversation = $this->conversationText($state);

        $provider = $this->provider();
        $model = $this->model($assistant->model());

        $request = $textRequestFactory->make()
            ->using($provider, $model)
            ->withSystemPrompt($promptText)
            ->withMessages([
                new UserMessage($conversation),
            ])
            ->withMaxSteps(max(1, $assistant->maxDefaultSteps() ?? 3));

        $maxTokens = $assistant->maxOutputTokens() ?? 300;
        $request->withMaxTokens($maxTokens);

        $providerOptions = $this->resolveProviderOptions($assistant, $provider);

        if ($providerOptions !== []) {
            $request->withProviderOptions($providerOptions);
        }

        $response = $request->asText();

        if ($response === null) {
            throw new RuntimeException('No response received when generating thread title and summary.');
        }

        $decoded = $this->decodePayload($response->text);

        $assistantThreadLogger->log(
            $state->thread,
            $assistant,
            sprintf('Summary for Thread %s', $state->thread->getKey()),
            $conversation,
            $response,
            ['thread_summary_payload' => $decoded['raw_payload']],
            ['thread_summary_payload' => $decoded['raw_payload']]
        );

        return [
            'title' => Str::limit($decoded['title'], 120, '...'),
            'summary' => Str::limit($decoded['summary'], 5000, ''),
            'keywords' => $decoded['keywords'],
        ];
    }

    /**
     * @return array{title: string, summary: string, keywords: array<int, string>, raw_payload: string}
     */
    protected function decodePayload(string $text): array
    {
        $cleaned = trim($text);
        $decoded = json_decode($cleaned, true);

        if (! is_array($decoded)) {
            if (str_starts_with($cleaned, '```')) {
                $cleaned = preg_replace('/^```(?:json)?/i', '', $cleaned) ?? $cleaned;
                $cleaned = preg_replace('/```$/', '', $cleaned) ?? $cleaned;
                $decoded = json_decode(trim($cleaned), true);
            }

            if (! is_array($decoded)) {
                $jsonBody = $this->extractJson($cleaned);

                if ($jsonBody !== null) {
                    $decoded = json_decode($jsonBody, true);
                }
            }

            if (! is_array($decoded)) {
                throw new RuntimeException('Unable to parse generated title and summary.');
            }
        }

        $title = $this->trimValue($decoded['title'] ?? null);
        $summary = $this->trimValue($decoded['summary'] ?? ($decoded['short_summary'] ?? null));

        if ($title === null || $summary === null) {
            throw new RuntimeException('Generated title or summary is missing.');
        }

        $keywords = $this->normalizeKeywords($decoded['keywords'] ?? null);

        return [
            'title' => $title,
            'summary' => $summary,
            'keywords' => $keywords,
            'raw_payload' => $text,
        ];
    }

    protected function extractJson(string $text): ?string
    {
        $start = strpos($text, '{');
        $end = strrpos($text, '}');

        if ($start === false || $end === false || $end <= $start) {
            return null;
        }

        return substr($text, $start, ($end - $start) + 1);
    }

    protected function conversationText(ThreadState $state): string
    {
        $messages = $state->messages;
        $lastSummaryId = $state->thread->last_summary_message_id;

        if ($lastSummaryId !== null) {
            $messages = $messages->filter(function (AiMessage $message) use ($lastSummaryId): bool {
                return $message->getKey() > $lastSummaryId;
            });
        }

        $recentMessages = $messages
            ->sortBy('sequence')
            ->map(function (AiMessage $message): string {
                $role = strtoupper($message->role->value ?? 'message');
                $content = trim((string) $message->content);

                return implode("\n", [
                    sprintf('%s:', $role),
                    $content,
                ]);
            })
            ->all();

        $joined = Str::limit(implode("\n\n", $recentMessages), 6000, '...');

        $summaryText = $this->trimValue($state->thread->summary) ?? 'None';
        $recentText = $joined === '' ? 'None' : $joined;
        $existingKeywords = $this->existingKeywords($state->thread);

        $keywordsSection = $existingKeywords === []
            ? '- None.'
            : '- '.implode("\n- ", $existingKeywords);

        $context = implode("\n", [
            '# Current thread summary:',
            $summaryText,
            '',
            '# Current keywords:',
            $keywordsSection,
            '',
            '# Recent messages from thread:',
            '',
            $recentText,
        ]);

        return Str::limit($context, 7000, '...');
    }

    protected function trimValue(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return array<int, string>
     */
    protected function normalizeKeywords(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $keywords = [];

        foreach ($value as $keyword) {
            if (! is_string($keyword)) {
                continue;
            }

            $trimmed = trim($keyword);

            if ($trimmed === '') {
                continue;
            }

            $keywords[] = $trimmed;
        }

        return array_slice($keywords, 0, 12);
    }

    /**
     * @return array<int, string>
     */
    protected function existingKeywords(AiThread $thread): array
    {
        /** @var array<string, mixed>|null $metadata */
        $metadata = $thread->metadata;
        /** @var array<string, mixed> $metadataArray */
        $metadataArray = $metadata ?? [];

        return $this->normalizeKeywords($metadataArray['keywords'] ?? null);
    }

    protected function provider(): string
    {
        $provider = config('prism.default_provider', 'openai');

        return is_string($provider) && $provider !== '' ? $provider : 'openai';
    }

    protected function model(?string $assistantModel): string
    {
        $model = $assistantModel
            ?? config('prism.default_model')
            ?? 'gpt-4o-mini';

        return is_string($model) && $model !== '' ? $model : 'gpt-4o-mini';
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveProviderOptions(ResolvedAssistant $assistant, string $provider): array
    {
        $options = [];

        if (mb_strtolower($provider) === 'openai') {
            $reasoning = $assistant->reasoning();

            if (is_array($reasoning) && $reasoning !== []) {
                $options['reasoning'] = $reasoning;
            }
        }

        return $options;
    }

    protected function resolveQueue(): ?string
    {
        $queue = config('atlas-nexus.queue');

        return is_string($queue) && $queue !== '' ? $queue : null;
    }
}
