<?php

declare(strict_types=1);

namespace Atlas\Nexus\Integrations\Prism\Tools;

use Atlas\Nexus\Contracts\ThreadStateAwareTool;
use Atlas\Nexus\Enums\AiMemoryOwnerType;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Services\Models\AiMemoryService;
use Atlas\Nexus\Support\Chat\ThreadState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\BooleanSchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\ObjectSchema;
use Prism\Prism\Schema\StringSchema;
use RuntimeException;
use Throwable;

/**
 * Class MemoryTool
 *
 * Built-in tool that allows assistants to save, retrieve, and remove scoped memories for the active user and thread.
 */
class MemoryTool extends AbstractTool implements ThreadStateAwareTool
{
    public const SLUG = 'atlas_memory';

    protected ?ThreadState $state = null;

    public function __construct(
        private readonly AiMemoryService $memoryService
    ) {}

    public static function toolSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'action' => ['type' => 'string', 'enum' => ['save', 'fetch', 'delete']],
                'kind' => ['type' => 'string', 'description' => 'Memory category such as fact, preference, or constraint'],
                'content' => ['type' => 'string', 'description' => 'Memory content to store'],
                'scope' => ['type' => 'string', 'enum' => ['user', 'assistant', 'global']],
                'thread_specific' => ['type' => 'boolean', 'description' => 'Whether the memory is tied to this thread'],
                'from_date' => ['type' => 'string', 'format' => 'date-time'],
                'to_date' => ['type' => 'string', 'format' => 'date-time'],
                'memory_id' => ['type' => 'number', 'description' => 'Identifier of the memory to remove'],
                'memory_ids' => ['type' => 'array', 'items' => ['type' => 'number'], 'description' => 'Array of memory IDs to remove'],
                'metadata' => ['type' => 'object'],
            ],
            'required' => ['action'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function toolRecordDefinition(): array
    {
        return [
            'slug' => self::SLUG,
            'name' => 'Memory Manager',
            'description' => 'Save, recall, and remove user and assistant memories.',
            'schema' => self::toolSchema(),
            'handler_class' => self::class,
            'is_active' => true,
        ];
    }

    public function setThreadState(ThreadState $state): void
    {
        $this->state = $state;
    }

    public function name(): string
    {
        return 'Memory Manager';
    }

    public function description(): string
    {
        return 'Access, store, and delete contextual memories for this assistant and user.';
    }

    /**
     * @return array<int, ToolParameter>
     */
    public function parameters(): array
    {
        return [
            new ToolParameter(new EnumSchema('action', 'Action to perform', ['save', 'fetch', 'delete'])),
            new ToolParameter(new StringSchema('kind', 'Memory type such as fact or preference', true), false),
            new ToolParameter(new StringSchema('content', 'Memory content to persist', true), false),
            new ToolParameter(new EnumSchema('scope', 'Target scope: user (default), assistant, or global', ['user', 'assistant', 'global'], true), false),
            new ToolParameter(new BooleanSchema('thread_specific', 'Associate the memory to this thread', true), false),
            new ToolParameter(new StringSchema('from_date', 'Earliest creation date (ISO8601)', true), false),
            new ToolParameter(new StringSchema('to_date', 'Latest creation date (ISO8601)', true), false),
            new ToolParameter(new NumberSchema('memory_id', 'Memory identifier to remove', true, minimum: 1), false),
            new ToolParameter(new ArraySchema('memory_ids', 'Memory identifiers to remove', new NumberSchema('id', 'Memory id')), false),
            new ToolParameter(new ObjectSchema('metadata', 'Optional metadata to store', [], [], allowAdditionalProperties: true, nullable: true), false),
        ];
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    public function handle(array $arguments): ToolResponse
    {
        if (! isset($this->state)) {
            return $this->output('Memory tool unavailable: missing thread context.', ['error' => true]);
        }

        $action = (string) ($arguments['action'] ?? 'fetch');

        try {
            return match ($action) {
                'save' => $this->handleSave($arguments),
                'delete' => $this->handleDelete($arguments),
                default => $this->handleFetch($arguments),
            };
        } catch (RuntimeException $exception) {
            return $this->output($exception->getMessage(), ['error' => true]);
        } catch (Throwable $exception) {
            return $this->output('Memory tool failed: '.$exception->getMessage(), ['error' => true]);
        }
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function handleSave(array $arguments): ToolResponse
    {
        $content = (string) ($arguments['content'] ?? '');
        $kind = (string) ($arguments['kind'] ?? 'note');

        if ($content === '') {
            return $this->output('Memory content is required to save a memory.', ['error' => true]);
        }

        $ownerType = $this->ownerTypeFromScope($arguments['scope'] ?? null);
        $threadScoped = (bool) ($arguments['thread_specific'] ?? false);
        $metadata = $this->normalizeMetadata($arguments['metadata'] ?? null);

        $memory = $this->memoryService->saveForThread(
            $this->state->assistant,
            $this->state->thread,
            $kind,
            $content,
            $ownerType,
            $metadata,
            $threadScoped
        );

        return $this->output('Memory saved.', [
            'memory_id' => $memory->id,
            'owner_type' => $memory->owner_type->value,
            'thread_id' => $memory->thread_id,
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function handleFetch(array $arguments): ToolResponse
    {
        $from = $this->parseDate($arguments['from_date'] ?? null);
        $to = $this->parseDate($arguments['to_date'] ?? null);

        $memories = $this->memoryService->listForThread(
            $this->state->assistant,
            $this->state->thread,
            $from,
            $to
        );

        return $this->output(
            $memories->isEmpty() ? 'No memories found.' : 'Memories retrieved.',
            [
                'memories' => $this->serializeMemories($memories),
            ]
        );
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function handleDelete(array $arguments): ToolResponse
    {
        $ids = $this->collectIds($arguments);

        if ($ids === []) {
            $available = $this->memoryService->listForThread($this->state->assistant, $this->state->thread);

            return $this->output(
                'Provide memory_id or memory_ids to delete. Use action=fetch first to list memories with their IDs.',
                [
                    'error' => true,
                    'available_memories' => $this->serializeMemories($available),
                ]
            );
        }

        $removed = [];
        $errors = [];

        foreach ($ids as $id) {
            try {
                $this->memoryService->removeForThread(
                    $this->state->assistant,
                    $this->state->thread,
                    $id
                );
                $removed[] = $id;
            } catch (RuntimeException $exception) {
                $errors[$id] = $exception->getMessage();
            }
        }

        $message = $errors === []
            ? 'Memory removed.'
            : 'Some memories could not be removed.';

        return $this->output($message, [
            'removed_ids' => $removed,
            'errors' => $errors,
        ]);
    }

    /**
     * @param  Collection<int, AiMemory>  $memories
     * @return array<int, array<string, mixed>>
     */
    protected function serializeMemories(Collection $memories): array
    {
        return $memories->map(function (AiMemory $memory): array {
            return [
                'id' => $memory->id,
                'kind' => $memory->kind,
                'content' => $memory->content,
                'thread_id' => $memory->thread_id,
                'created_at' => $memory->created_at?->toAtomString(),
            ];
        })->all();
    }

    protected function ownerTypeFromScope(string|null $scope): AiMemoryOwnerType
    {
        return match ($scope) {
            'assistant' => AiMemoryOwnerType::ASSISTANT,
            'global' => AiMemoryOwnerType::ORG,
            default => AiMemoryOwnerType::USER,
        };
    }

    /**
     * @param  mixed  $metadata
     * @return array<string, mixed>|null
     */
    protected function normalizeMetadata(mixed $metadata): ?array
    {
        if ($metadata === null) {
            return null;
        }

        if (is_array($metadata)) {
            return $metadata;
        }

        return ['value' => $metadata];
    }

    protected function parseDate(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return Carbon::parse($value);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<int, int>
     */
    protected function collectIds(array $arguments): array
    {
        $ids = [];

        if (isset($arguments['memory_id']) && $arguments['memory_id'] !== null) {
            $ids[] = (int) $arguments['memory_id'];
        }

        if (isset($arguments['memory_ids']) && is_array($arguments['memory_ids'])) {
            foreach ($arguments['memory_ids'] as $id) {
                $intId = (int) $id;

                if ($intId > 0) {
                    $ids[] = $intId;
                }
            }
        }

        return array_values(array_unique(array_filter($ids, static fn (int $id): bool => $id > 0)));
    }
}
