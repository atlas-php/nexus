<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Integrations\Prism\TextRequestFactory;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Models\AiAssistantService;
use Atlas\Nexus\Services\Models\AiThreadService;
use Atlas\Nexus\Support\Chat\ThreadState;
use Atlas\Nexus\Support\Threads\ThreadManagerDefaults;
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
        private readonly AiAssistantService $assistantService
    ) {}

    /**
     * Generate and persist a title and summary based on the thread conversation.
     *
     * @return array{thread: AiThread, title: string, summary: string}
     */
    public function generateAndSave(ThreadState $state): array
    {
        $generated = $this->generate($state);

        $updated = $this->threadService->update($state->thread, [
            'title' => $generated['title'],
            'summary' => $generated['summary'],
        ]);

        return [
            'thread' => $updated,
            'title' => $generated['title'],
            'summary' => $generated['summary'],
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

        if ($normalizedTitle !== null) {
            $payload['title'] = $normalizedTitle;
        }

        if ($normalizedSummary !== null) {
            $payload['summary'] = $normalizedSummary;
        }

        return $this->threadService->update($state->thread, $payload);
    }

    /**
     * @return array{title: string, summary: string}
     */
    protected function generate(ThreadState $state): array
    {
        $conversation = $this->conversationText($state);

        if ($conversation === '') {
            throw new RuntimeException('Cannot generate title and summary without conversation messages.');
        }

        $assistant = $this->assistantService->query()
            ->where('slug', ThreadManagerDefaults::ASSISTANT_SLUG)
            ->with('currentPrompt')
            ->first();

        if ($assistant === null || $assistant->currentPrompt === null) {
            throw new RuntimeException('Thread manager assistant or prompt is missing. Please run the Nexus seeders.');
        }

        $prompt = $assistant->currentPrompt->system_prompt;

        $request = $this->textRequestFactory->make()
            ->using($this->provider(), $this->model($assistant->default_model))
            ->withSystemPrompt($prompt)
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
        $summary = $this->trimValue($decoded['summary'] ?? null);

        if ($title === null || $summary === null) {
            throw new RuntimeException('Generated title or summary is missing.');
        }

        return [
            'title' => Str::limit($title, 120, '...'),
            'summary' => Str::limit($summary, 1500, '...'),
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

    protected function provider(): string
    {
        $provider = config('prism.default_provider', 'openai');

        return is_string($provider) && $provider !== '' ? $provider : 'openai';
    }

    protected function model(?string $assistantModel): string
    {
        $model = $assistantModel
            ?? config('atlas-nexus.tools.thread_manager.model')
            ?? config('prism.default_model')
            ?? 'gpt-4o-mini';

        return is_string($model) && $model !== '' ? $model : 'gpt-4o-mini';
    }
}
