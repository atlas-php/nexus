<?php

declare(strict_types=1);

namespace Atlas\Nexus\Integrations\Prism\Tools;

use Atlas\Nexus\Contracts\ThreadStateAwareTool;
use Atlas\Nexus\Enums\AiMemoryOwnerType;
use Atlas\Nexus\Models\AiMemory;
use Atlas\Nexus\Services\Models\AiMemoryService;
use Atlas\Nexus\Support\Chat\ThreadState;
use Atlas\Nexus\Support\Tools\ToolDefinition;
use Illuminate\Support\Collection;
use Prism\Prism\Schema\ArraySchema;
use Prism\Prism\Schema\EnumSchema;
use Prism\Prism\Schema\NumberSchema;
use Prism\Prism\Schema\StringSchema;
use RuntimeException;
use Throwable;

/**
 * Class MemoryTool
 *
 * Built-in tool that allows assistants to add, update, fetch, and remove scoped memories for the active user and thread.
 */
class MemoryTool extends AbstractTool implements ThreadStateAwareTool
{
    public const KEY = 'memory';

    private const DEFAULT_TYPE = 'fact';

    /**
     * @var array<int, string>
     */
    private const SUPPORTED_TYPES = ['fact', 'preference', 'constraint'];

    protected ?ThreadState $state = null;

    public function __construct(
        private readonly AiMemoryService $memoryService
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
        return 'Memory';
    }

    public function description(): string
    {
        return 'Add, update, fetch, or delete contextual memories for this user.';
    }

    /**
     * @return array<int, ToolParameter>
     */
    public function parameters(): array
    {
        return [
            new ToolParameter(new EnumSchema('action', 'Action to perform', ['add', 'update', 'fetch', 'delete'])),
            new ToolParameter(new EnumSchema('type', 'Memory type', self::SUPPORTED_TYPES, true), false),
            new ToolParameter(new StringSchema('content', 'Memory content to store', true), false),
            new ToolParameter(new ArraySchema(
                'memory_ids',
                'Memory ID(s) for fetching, updating, or deleting one or many',
                new NumberSchema('id', 'Memory id')
            ), false),
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

        $state = $this->state;

        $action = strtolower((string) ($arguments['action'] ?? 'fetch'));

        try {
            return match ($action) {
                'add' => $this->handleAdd($arguments, $state),
                'update' => $this->handleUpdate($arguments, $state),
                'delete' => $this->handleDelete($arguments, $state),
                default => $this->handleFetch($arguments, $state),
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
    protected function handleAdd(array $arguments, ThreadState $state): ToolResponse
    {
        $content = $this->normalizeContent($arguments['content'] ?? null);

        if ($content === null) {
            return $this->output('Memory content is required to add a memory.', ['error' => true]);
        }

        $type = $this->normalizeType($arguments['type'] ?? null) ?? self::DEFAULT_TYPE;

        $memory = $this->memoryService->saveForThread(
            $state->assistant,
            $state->thread,
            $type,
            $content,
            AiMemoryOwnerType::USER
        );

        return $this->output('Memory added.', [
            'memory_ids' => [$memory->id],
            'type' => $memory->kind,
            'content' => $memory->content,
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function handleUpdate(array $arguments, ThreadState $state): ToolResponse
    {
        $ids = $this->collectIds($arguments);

        if (count($ids) > 1) {
            return $this->output('You can only update 1 memory at a time', ['error' => true]);
        }

        $memoryId = $this->requireMemoryId($arguments, 'update');
        $content = $this->normalizeContent($arguments['content'] ?? null);
        $type = $this->normalizeType($arguments['type'] ?? null);

        if ($content === null && $type === null) {
            return $this->output('Provide new content or type to update a memory.', ['error' => true]);
        }

        $memory = $this->memoryService->updateForThread(
            $state->assistant,
            $state->thread,
            $memoryId,
            $type,
            $content
        );

        return $this->output('Memory updated.', [
            'memory_ids' => [$memory->id],
            'type' => $memory->kind,
            'content' => $memory->content,
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function handleFetch(array $arguments, ThreadState $state): ToolResponse
    {
        $ids = $this->collectIds($arguments);

        $memories = $this->memoryService->listForThread(
            $state->assistant,
            $state->thread,
            $ids === [] ? null : $ids
        );

        $serialized = $this->serializeMemories($memories);
        $message = $memories->isEmpty()
            ? 'No memories found.'
            : "Memories found:\n".implode("\n", array_map(
                static fn (array $memory): string => sprintf(
                    '- ID %s (%s): %s',
                    $memory['id'],
                    $memory['type'],
                    $memory['content']
                ),
                $serialized
            ));

        return $this->output($message, [
            'memories' => $serialized,
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function handleDelete(array $arguments, ThreadState $state): ToolResponse
    {
        $ids = $this->collectIds($arguments);

        if ($ids === []) {
            return $this->output(
                'Provide memory_ids to delete. Use action=fetch first to list memories with their IDs.',
                [
                    'error' => true,
                ]
            );
        }

        $removed = [];
        $errors = [];

        foreach ($ids as $id) {
            try {
                $this->memoryService->removeForThread(
                    $state->assistant,
                    $state->thread,
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
            $ownerType = $memory->owner_type;

            return [
                'id' => $memory->id,
                'type' => $memory->kind,
                'content' => $memory->content,
                'assistant_id' => $memory->assistant_id,
                'owner_type' => $ownerType->value,
                'user_id' => $ownerType === AiMemoryOwnerType::USER ? $memory->owner_id : null,
                'thread_id' => $memory->thread_id,
                'created_at' => $memory->created_at?->toAtomString(),
            ];
        })->all();
    }

    protected function normalizeContent(mixed $value): ?string
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? null : $trimmed;
        }

        if (is_scalar($value)) {
            return $this->normalizeContent((string) $value);
        }

        return null;
    }

    protected function normalizeType(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $normalized = strtolower(trim($value));

        if ($normalized === '') {
            return null;
        }

        if (! in_array($normalized, self::SUPPORTED_TYPES, true)) {
            throw new RuntimeException('Memory type must be one of: '.implode(', ', self::SUPPORTED_TYPES).'.');
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function requireMemoryId(array $arguments, string $context): int
    {
        $ids = $this->collectIds($arguments);
        $memoryId = $ids[0] ?? null;

        if ($memoryId === null) {
            throw new RuntimeException(sprintf('memory_ids is required to %s a memory.', $context));
        }

        if ($memoryId <= 0) {
            throw new RuntimeException('memory_ids must contain positive integers.');
        }

        return $memoryId;
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<int, int>
     */
    protected function collectIds(array $arguments): array
    {
        $rawIds = $arguments['memory_ids'] ?? [];

        if (is_scalar($rawIds) || $rawIds instanceof \Stringable) {
            $rawIds = [(string) $rawIds];
        }

        if (! is_array($rawIds)) {
            return [];
        }

        $ids = [];

        foreach ($rawIds as $id) {
            $intId = (int) $id;

            if ($intId > 0) {
                $ids[] = $intId;
            }
        }

        return array_values(array_unique($ids));
    }
}
