<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Integrations\Prism\TextRequestFactory;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Assistants\AssistantRegistry;
use Atlas\Nexus\Services\Assistants\ResolvedAssistant;
use Atlas\Nexus\Services\Models\AiThreadService;
use Atlas\Nexus\Services\Prompts\PromptVariableContext;
use Atlas\Nexus\Services\Prompts\PromptVariableService;
use Atlas\Nexus\Services\Threads\Data\ThreadState;
use Illuminate\Support\Str;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use RuntimeException;

/**
 * Class ThreadTitleSummaryService
 *
 * Generates or applies thread titles and summaries inline, using a lightweight model when requested.
 */
class ThreadTitleSummaryService
{
    private const THREAD_MANAGER_KEY = 'thread-manager';

    public function __construct(
        private readonly TextRequestFactory $textRequestFactory,
        private readonly AiThreadService $threadService,
        private readonly PromptVariableService $promptVariableService,
        private readonly AssistantThreadLogger $assistantThreadLogger,
        private readonly AssistantRegistry $assistantRegistry
    ) {}

    /**
     * Generate and persist a title and summary based on the thread conversation.
     *
     * @return array{thread: AiThread, title: string|null, summary: string|null, keywords: array<int, string>}
     */
    public function generateAndSave(ThreadState $state, bool $preserveExistingTitle = true): array
    {
        $generated = $this->generate($state);

        $metadata = array_merge($state->thread->metadata ?? [], [
            'keywords' => $generated['keywords'],
        ]);

        $payload = [
            'summary' => $generated['summary'],
            'metadata' => $metadata,
        ];

        if ($state->thread->title === null) {
            $payload['title'] = $generated['title'];
        }

        $updated = $this->threadService->update($state->thread, $payload);

        return [
            'thread' => $updated,
            'title' => $updated->title,
            'summary' => $updated->summary ?? $generated['summary'],
            'keywords' => $generated['keywords'],
        ];
    }

    /**
     * Apply provided title and/or summary to the thread.
     */
    public function apply(ThreadState $state, ?string $title, ?string $summary): AiThread
    {
        $normalizedTitle = $this->trimValue($title);
        $normalizedSummary = $this->trimValue($summary);

        if ($normalizedTitle === null && $normalizedSummary === null) {
            throw new RuntimeException('Provide a title or summary to update the thread.');
        }

        $payload = [];

        if ($normalizedTitle !== null && $state->thread->title === null) {
            $payload['title'] = $normalizedTitle;
        }

        if ($normalizedSummary !== null) {
            $payload['summary'] = $normalizedSummary;
        }

        return $this->threadService->update($state->thread, $payload);
    }

    /**
     * @return array{title: string, summary: string, keywords: array<int, string>}
     */
    protected function generate(ThreadState $state): array
    {
        if ($state->messages->isEmpty()) {
            throw new RuntimeException('Cannot generate title and summary without conversation messages.');
        }

        $assistant = $this->threadManagerAssistant();
        $prompt = $assistant->systemPrompt();

        if ($prompt === '') {
            throw new RuntimeException('Thread manager assistant prompt is missing.');
        }

        $promptContext = new PromptVariableContext($state, $assistant, $prompt);
        $promptText = $this->promptVariableService->apply($prompt, $promptContext);
        $conversation = $this->conversationText($state);

        $provider = $this->provider();
        $model = $this->model($assistant->model());

        $request = $this->textRequestFactory->make()
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

        $normalized = [
            'title' => Str::limit($decoded['title'], 120, '...'),
            'summary' => Str::limit($decoded['summary'], 5000, ''),
            'keywords' => $decoded['keywords'],
        ];

        $metadata = [
            'thread_manager_payload' => $decoded['raw_payload'],
        ];

        $this->assistantThreadLogger->log(
            $state->thread,
            $assistant,
            sprintf('Summary for Thread %s', $state->thread->getKey()),
            $conversation,
            $response,
            $metadata,
            $metadata
        );

        return $normalized;
    }

    protected function threadManagerAssistant(): ResolvedAssistant
    {
        return $this->assistantRegistry->require(self::THREAD_MANAGER_KEY);
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
        $limitedContext = Str::limit($context, 7000, '...');

        return implode("\n", [
            $limitedContext,
        ]);
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
        $metadata = $thread->metadata ?? [];

        return $this->normalizeKeywords($metadata['keywords'] ?? null);
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

        if (strtolower($provider) === 'openai') {
            $reasoning = $assistant->reasoning();

            if (is_array($reasoning) && $reasoning !== []) {
                $options['reasoning'] = $reasoning;
            }
        }

        return $options;
    }
}
