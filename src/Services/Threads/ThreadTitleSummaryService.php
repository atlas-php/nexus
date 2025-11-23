<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Enums\AiMessageContentType;
use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Enums\AiThreadStatus;
use Atlas\Nexus\Enums\AiThreadType;
use Atlas\Nexus\Integrations\Prism\TextRequestFactory;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Assistants\AssistantRegistry;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Models\AiThreadService;
use Atlas\Nexus\Services\Prompts\PromptVariableService;
use Atlas\Nexus\Support\Assistants\ResolvedAssistant;
use Atlas\Nexus\Support\Chat\ThreadState;
use Atlas\Nexus\Support\Prism\TextResponseSerializer;
use Atlas\Nexus\Support\Prompts\PromptVariableContext;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Prism\Prism\Text\Response;
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
        private readonly AiMessageService $messageService,
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
        $conversation = $this->conversationText($state);

        if ($conversation === '') {
            throw new RuntimeException('Cannot generate title and summary without conversation messages.');
        }

        $assistant = $this->threadManagerAssistant();
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
            ->withMaxSteps(max(1, $assistant->maxDefaultSteps() ?? 3));

        $maxTokens = $assistant->maxOutputTokens() ?? 300;
        $request->withMaxTokens($maxTokens);

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

        $this->logThreadManagerConversation(
            $state,
            $assistant,
            $promptText,
            $conversation,
            $response,
            $normalized['summary'],
            $decoded['raw_payload']
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

    protected function logThreadManagerConversation(
        ThreadState $state,
        ResolvedAssistant $assistant,
        string $promptText,
        string $conversation,
        Response $response,
        string $summaryText,
        string $rawPayload
    ): void {
        $summaryThread = $this->threadService->create([
            'assistant_key' => $assistant->key(),
            'user_id' => $state->thread->user_id,
            'group_id' => $state->thread->group_id,
            'type' => AiThreadType::TOOL->value,
            'parent_thread_id' => $state->thread->getKey(),
            'title' => sprintf('Summary for Thread %s', $state->thread->getKey()),
            'status' => AiThreadStatus::CLOSED->value,
            'summary' => null,
            'metadata' => [
                'source_thread_id' => $state->thread->getKey(),
                'thread_manager_payload' => $rawPayload,
            ],
            'last_message_at' => Carbon::now(),
        ]);

        $conversationContent = sprintf("# Prompt\n%s\n\n# Conversation\n%s", $promptText, $conversation);

        $this->messageService->create([
            'thread_id' => $summaryThread->id,
            'assistant_key' => $assistant->key(),
            'user_id' => $state->thread->user_id,
            'group_id' => $state->thread->group_id,
            'role' => AiMessageRole::USER->value,
            'content' => $conversationContent,
            'content_type' => AiMessageContentType::TEXT->value,
            'sequence' => 1,
            'status' => AiMessageStatus::COMPLETED->value,
        ]);

        $this->messageService->create([
            'thread_id' => $summaryThread->id,
            'assistant_key' => $assistant->key(),
            'user_id' => null,
            'group_id' => $state->thread->group_id,
            'role' => AiMessageRole::ASSISTANT->value,
            'content' => $response->text,
            'content_type' => AiMessageContentType::TEXT->value,
            'sequence' => 2,
            'status' => AiMessageStatus::COMPLETED->value,
            'model' => $response->meta->model ?? $assistant->model(),
            'tokens_in' => $response->usage->promptTokens,
            'tokens_out' => $response->usage->completionTokens,
            'provider_response_id' => $response->meta->id ?? null,
            'raw_response' => TextResponseSerializer::serialize($response),
            'metadata' => [
                'thread_manager_payload' => $rawPayload,
            ],
        ]);
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
