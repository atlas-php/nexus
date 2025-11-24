<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Integrations\Prism\TextRequestFactory;
use Atlas\Nexus\Models\AiMemory;
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
        private readonly AiMessageService $messageService,
        private readonly AssistantThreadLogger $assistantThreadLogger
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

        $checkedMessageIds = $this->messageIds($messages);

        $metadata = [
            'memory_extractor_payload' => $payload,
            'checked_message_ids' => $checkedMessageIds,
            'extracted_memories' => $memories,
        ];

        $this->assistantThreadLogger->log(
            $thread,
            $assistant,
            sprintf('Memory extraction for Thread %s', $thread->getKey()),
            $payload,
            $response,
            $metadata,
            $metadata
        );

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
            ->userMemories($thread->user_id, $thread->assistant_key)
            ->map(fn (AiMemory $memory): string => (string) $memory->content)
            ->filter(static fn (string $content): bool => $content !== '')
            ->values();

        $threadMemories = $this->threadMemoryService
            ->memoriesForThread($thread)
            ->map(fn (AiMemory $memory): string => (string) $memory->content)
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

        return "Analyze the payload below and extract new memories as a JSON array of strings.\n".$encoded;
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

        $payload = $this->resolveMemoryPayload($decoded);
        $normalized = [];

        foreach ($payload as $memory) {
            if (is_string($memory)) {
                $content = $this->stringValue($memory);
                $sourceIds = [];
            } elseif (is_array($memory)) {
                $content = $this->stringValue($memory['content'] ?? null);
                $sourceIds = $this->normalizeMessageIds($memory['source_message_ids'] ?? []);
            } else {
                continue;
            }

            if ($content === null) {
                continue;
            }

            $normalized[] = [
                'content' => $content,
                'source_message_ids' => $sourceIds,
            ];
        }

        return $normalized;
    }

    /**
     * @param  array<mixed>  $decoded
     * @return array<int, mixed>
     */
    private function resolveMemoryPayload(array $decoded): array
    {
        if ($decoded === []) {
            return [];
        }

        if (array_is_list($decoded)) {
            return $decoded;
        }

        $memories = $decoded['memories'] ?? null;

        if (is_array($memories)) {
            return $memories;
        }

        throw new RuntimeException('Unable to decode memory extraction response.');
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
        $ids = $this->messageIds($messages);

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

    /**
     * @param  Collection<int, AiMessage>  $messages
     * @return array<int, int>
     */
    private function messageIds(Collection $messages): array
    {
        return $messages
            ->map(static fn (AiMessage $message): ?int => $message->getKey())
            ->filter(static fn (?int $id): bool => $id !== null)
            ->values()
            ->all();
    }
}
