<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Integrations\Prism\TextRequestFactory;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Assistants\AssistantRegistry;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Support\Assistants\ResolvedAssistant;
use Illuminate\Support\Collection;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use RuntimeException;

use function json_decode;
use function json_encode;

/**
 * Class ThreadMemoryExtractionService
 *
 * Sends recent thread messages to the memory extractor assistant and persists any new deduplicated memories.
 */
class ThreadMemoryExtractionService
{
    private const MEMORY_EXTRACTOR_KEY = 'memory-extractor';

    public function __construct(
        private readonly AssistantRegistry $assistantRegistry,
        private readonly ThreadMemoryService $threadMemoryService,
        private readonly TextRequestFactory $textRequestFactory,
        private readonly AiMessageService $messageService
    ) {}

    /**
     * @param  Collection<int, AiMessage>  $messages
     */
    public function extractFromMessages(AiThread $thread, Collection $messages): void
    {
        if ($messages->isEmpty()) {
            return;
        }

        $assistant = $this->assistantRegistry->require(self::MEMORY_EXTRACTOR_KEY);
        $payload = $this->buildPayload($thread, $messages);

        $request = $this->textRequestFactory->make()
            ->using($this->provider(), $this->model($assistant))
            ->withSystemPrompt($assistant->systemPrompt())
            ->withMessages([new UserMessage($payload)])
            ->withMaxSteps(max(1, $assistant->maxDefaultSteps() ?? 1))
            ->withMaxTokens($assistant->maxOutputTokens() ?? 400);

        $response = $request->asText();

        if ($response === null) {
            throw new RuntimeException('Memory extractor assistant did not return a response.');
        }

        $memories = $this->decodeMemories($response->text);

        if ($memories !== []) {
            $this->threadMemoryService->appendMemories($thread, $memories);
        }

        $this->markMessagesChecked($messages);
    }

    private function provider(): string
    {
        $provider = config('prism.default_provider', 'openai');

        return is_string($provider) && $provider !== '' ? $provider : 'openai';
    }

    private function model(ResolvedAssistant $assistant): string
    {
        $model = $assistant->model() ?? config('prism.default_model') ?? 'gpt-4o-mini';

        return is_string($model) && $model !== '' ? $model : 'gpt-4o-mini';
    }

    /**
     * @param  Collection<int, AiMessage>  $messages
     */
    private function buildPayload(AiThread $thread, Collection $messages): string
    {
        $userMemories = $this->threadMemoryService
            ->userMemories($thread->user_id)
            ->map(fn (array $memory): string => (string) ($memory['content'] ?? ''))
            ->filter(static fn (string $content): bool => $content !== '')
            ->values();

        $threadMemories = $this->threadMemoryService
            ->memoriesForThread($thread)
            ->map(fn (array $memory): string => (string) ($memory['content'] ?? ''))
            ->filter(static fn (string $content): bool => $content !== '')
            ->values();

        $messagePayload = $messages
            ->map(function (AiMessage $message): array {
                /** @var AiMessageRole $roleEnum */
                $roleEnum = $message->role;
                $role = $roleEnum->value;

                return [
                    'id' => $message->getKey(),
                    'role' => $role,
                    'content' => $message->content,
                    'sequence' => $message->sequence,
                ];
            })
            ->values();

        $payload = [
            'thread_id' => $thread->getKey(),
            'existing_memories' => $userMemories->all(),
            'current_thread_memories' => $threadMemories->all(),
            'new_messages' => $messagePayload->all(),
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            throw new RuntimeException('Unable to encode memory extraction payload.');
        }

        return "Analyze the payload below and extract new memories.\n".$encoded;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeMemories(string $raw): array
    {
        $cleaned = trim($raw);
        $decoded = json_decode($cleaned, true);

        if (! is_array($decoded)) {
            if (str_starts_with($cleaned, '```')) {
                $cleaned = preg_replace('/^```(?:json)?/i', '', $cleaned) ?? $cleaned;
                $cleaned = preg_replace('/```$/', '', $cleaned) ?? $cleaned;
                $decoded = json_decode(trim($cleaned), true);
            }
        }

        if (! is_array($decoded)) {
            $jsonBody = $this->extractJson($cleaned);

            if ($jsonBody !== null) {
                $decoded = json_decode($jsonBody, true);
            }
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Unable to decode memory extraction response.');
        }

        $memories = $decoded['memories'] ?? [];

        if (! is_array($memories)) {
            return [];
        }

        $normalized = [];

        foreach ($memories as $memory) {
            $content = $this->stringValue($memory['content'] ?? null);

            if ($content === null) {
                continue;
            }

            $normalized[] = [
                'content' => $content,
                'source_message_ids' => $this->normalizeMessageIds($memory['source_message_ids'] ?? []),
            ];
        }

        return $normalized;
    }

    private function extractJson(string $text): ?string
    {
        $matches = [];

        if (preg_match('/\{.*\}/s', $text, $matches) === 1) {
            return $matches[0];
        }

        return null;
    }

    /**
     * @param  Collection<int, AiMessage>  $messages
     */
    private function markMessagesChecked(Collection $messages): void
    {
        $ids = $messages
            ->map(static fn (AiMessage $message): ?int => $message->getKey())
            ->filter(static fn (?int $id): bool => $id !== null)
            ->values()
            ->all();

        if ($ids === []) {
            return;
        }

        $this->messageService->query()
            ->whereIn('id', $ids)
            ->update(['is_memory_checked' => true]);
    }

    private function stringValue(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @param  mixed  $ids
     * @return array<int, int>
     */
    private function normalizeMessageIds($ids): array
    {
        if (! is_array($ids)) {
            return [];
        }

        $normalized = [];

        foreach ($ids as $id) {
            if (is_int($id)) {
                $normalized[] = $id;
            } elseif (is_string($id) && ctype_digit($id)) {
                $normalized[] = (int) $id;
            }
        }

        return array_values(array_unique($normalized));
    }
}
