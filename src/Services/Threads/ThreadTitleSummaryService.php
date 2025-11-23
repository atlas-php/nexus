<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Integrations\Prism\TextRequestFactory;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Models\AiThreadService;
use Atlas\Nexus\Services\Prompts\PromptVariableService;
use Atlas\Nexus\Support\Chat\ThreadState;
use Atlas\Nexus\Support\Prompts\PromptVariableContext;
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
    public function __construct(
        private readonly TextRequestFactory $textRequestFactory,
        private readonly AiThreadService $threadService,
        private readonly PromptVariableService $promptVariableService
    ) {}

    /**
     * Generate and persist a title and summary based on the thread conversation.
     *
     * @param  bool  $preserveExistingTitle  When true, retains the existing title when available.
     * @return array{thread: AiThread, title: string|null, summary: string|null, long_summary: string|null, keywords: array<int, string>}
     */
    public function generateAndSave(ThreadState $state, bool $preserveExistingTitle = false): array
    {
        $generated = $this->generate($state);

        $metadata = array_merge($state->thread->metadata ?? [], [
            'summary_keywords' => $generated['keywords'],
        ]);

        $payload = [
            'summary' => $generated['summary'],
            'long_summary' => $generated['long_summary'],
            'metadata' => $metadata,
        ];

        $shouldUpdateTitle = ! $preserveExistingTitle || $state->thread->title === null;

        if ($shouldUpdateTitle) {
            $payload['title'] = $generated['title'];
        }

        $updated = $this->threadService->update($state->thread, $payload);

        return [
            'thread' => $updated,
            'title' => $updated->title,
            'summary' => $updated->summary ?? $generated['summary'],
            'long_summary' => $updated->long_summary ?? $generated['long_summary'],
            'keywords' => $generated['keywords'],
        ];
    }

    /**
     * Apply provided title and/or summary to the thread.
     */
    public function apply(ThreadState $state, ?string $title, ?string $summary, ?string $longSummary = null): AiThread
    {
        $normalizedTitle = $this->trimValue($title);
        $normalizedSummary = $this->trimValue($summary);
        $normalizedLongSummary = $this->trimValue($longSummary);

        if ($normalizedTitle === null && $normalizedSummary === null && $normalizedLongSummary === null) {
            throw new RuntimeException('Provide a title, short summary, or long summary to update the thread.');
        }

        $payload = [];

        if ($normalizedTitle !== null) {
            $payload['title'] = $normalizedTitle;
        }

        if ($normalizedSummary !== null) {
            $payload['summary'] = $normalizedSummary;
        }

        if ($normalizedLongSummary !== null) {
            $payload['long_summary'] = $normalizedLongSummary;
        }

        return $this->threadService->update($state->thread, $payload);
    }

    /**
     * @return array{title: string, summary: string, long_summary: string, keywords: array<int, string>}
     */
    protected function generate(ThreadState $state): array
    {
        $conversation = $this->conversationText($state);

        if ($conversation === '') {
            throw new RuntimeException('Cannot generate title and summary without conversation messages.');
        }

        $assistant = $state->assistant;
        $prompt = $assistant->systemPrompt();

        if ($prompt === '') {
            throw new RuntimeException('Thread manager assistant prompt is missing.');
        }

        $promptContext = new PromptVariableContext($state, $assistant, $prompt);
        $promptText = $this->promptVariableService->apply($prompt, $promptContext);

        $request = $this->textRequestFactory->make()
            ->using($this->provider(), $this->model($assistant->model()))
            ->withSystemPrompt($promptText)
            ->withMessages([
                new UserMessage($conversation),
            ])
            ->withMaxTokens(300)
            ->withMaxSteps(3);

        $response = $request->asText();

        if ($response === null) {
            throw new RuntimeException('No response received when generating thread title and summary.');
        }

        $decoded = json_decode($response->text, true);

        if (! is_array($decoded)) {
            throw new RuntimeException('Unable to parse generated title and summary.');
        }

        $title = $this->trimValue($decoded['title'] ?? null);
        $shortSummary = $this->trimValue($decoded['short_summary'] ?? ($decoded['summary'] ?? null));
        $longSummary = $this->trimValue($decoded['long_summary'] ?? null);
        $keywords = $this->normalizeKeywords($decoded['keywords'] ?? null);

        if ($title === null || $shortSummary === null || $longSummary === null) {
            throw new RuntimeException('Generated title or summaries are missing.');
        }

        return [
            'title' => Str::limit($title, 120, '...'),
            'summary' => Str::limit($shortSummary, 255, ''),
            'long_summary' => Str::limit($longSummary, 5000, '...'),
            'keywords' => $keywords,
        ];
    }

    protected function conversationText(ThreadState $state): string
    {
        if ($state->messages->isEmpty()) {
            return '';
        }

        $messages = $state->messages
            ->sortBy('sequence')
            ->slice(max(0, $state->messages->count() - 10))
            ->map(function ($message): string {
                $role = $message->role->value ?? 'message';

                return strtoupper($role).': '.trim((string) $message->content);
            })
            ->all();

        $joined = implode("\n", $messages);

        return Str::limit($joined, 6000, '...');
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
}
