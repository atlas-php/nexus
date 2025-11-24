<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Contracts\ConfigurableTool;
use Atlas\Nexus\Contracts\NexusTool;
use Atlas\Nexus\Contracts\ThreadStateAwareTool;
use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Enums\AiToolRunStatus;
use Atlas\Nexus\Integrations\OpenAI\OpenAiRateLimitClient;
use Atlas\Nexus\Integrations\Prism\TextRequest;
use Atlas\Nexus\Integrations\Prism\TextRequestFactory;
use Atlas\Nexus\Jobs\PushMemoryExtractorAssistantJob;
use Atlas\Nexus\Jobs\PushThreadManagerAssistantJob;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiToolRun;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Models\AiThreadService;
use Atlas\Nexus\Services\Models\AiToolRunService;
use Atlas\Nexus\Services\Tools\ToolRunLogger;
use Atlas\Nexus\Support\Chat\ChatThreadLog;
use Atlas\Nexus\Support\Chat\ThreadState;
use Atlas\Nexus\Support\Prism\TextResponseSerializer;
use Atlas\Nexus\Support\Tools\ToolDefinition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ProviderTool;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use RuntimeException;
use Throwable;

/**
 * Class AssistantResponseService
 *
 * Generates assistant responses for a thread, handling Prism calls, tool logging, and failure tracking for both queued and inline execution.
 */
class AssistantResponseService
{
    private const THREAD_MANAGER_KEY = 'thread-manager';

    private const MEMORY_EXTRACTOR_KEY = 'memory-extractor';

    /**
     * @var array<string, array<string, mixed>|int|float|string|null>
     */
    protected array $recordedToolOutputs = [];

    public function __construct(
        private readonly ThreadStateService $threadStateService,
        private readonly AiMessageService $messageService,
        private readonly AiThreadService $threadService,
        private readonly AiToolRunService $toolRunService,
        private readonly TextRequestFactory $textRequestFactory,
        private readonly ToolRunLogger $toolRunLogger,
        private readonly OpenAiRateLimitClient $openAiRateLimitClient,
    ) {}

    public function handle(int $assistantMessageId): void
    {
        $this->recordedToolOutputs = [];
        $textRequest = null;
        $providerKey = null;
        $modelKey = null;

        /** @var AiMessage $assistantMessage */
        $assistantMessage = $this->messageService->findOrFail($assistantMessageId);
        $assistantMessage->loadMissing('thread');

        try {
            if ($assistantMessage->thread === null) {
                throw new RuntimeException('Assistant message is not associated with a thread.');
            }

            $state = $this->threadStateService->forThread($assistantMessage->thread);
            $this->messageService->markStatus($assistantMessage, AiMessageStatus::PROCESSING);

            $toolContext = $this->prepareTools($state, $assistantMessage->id);
            $chatLog = new ChatThreadLog;
            $textRequest = $this->textRequestFactory->make($chatLog);
            $providerKey = $this->resolveProvider();
            $modelKey = $this->resolveModel($state);

            $request = $textRequest
                ->using($providerKey, $modelKey)
                ->withMessages($this->convertMessages($state))
                ->withMaxSteps($this->resolveMaxSteps($state));

            $maxTokens = $state->assistant->maxOutputTokens();

            if ($maxTokens !== null) {
                $request->withMaxTokens($maxTokens);
            }

            $temperature = $state->assistant->temperature();

            if ($temperature !== null) {
                $request->usingTemperature($temperature);
            }

            $topP = $state->assistant->topP();

            if ($topP !== null) {
                $request->usingTopP($topP);
            }

            if ($state->systemPrompt !== null) {
                $request->withSystemPrompt($state->systemPrompt);
            }

            if ($toolContext['tools'] !== []) {
                $request->withTools($toolContext['tools']);
            }

            $providerTools = $this->prepareProviderTools($state);

            if ($providerTools !== []) {
                $request->withProviderTools($providerTools);
            }

            $providerOptions = $this->resolveProviderOptions($state, $providerKey);

            if ($providerOptions !== []) {
                $request->withProviderOptions($providerOptions);
            }

            $response = $textRequest->asText();

            if ($response === null) {
                throw new RuntimeException('Prism did not return a response for the assistant message.');
            }

            DB::transaction(function () use ($assistantMessage, $response, $toolContext, $state): void {
                $toolRunIds = $this->recordToolResults(
                    $response->toolCalls,
                    $response->toolResults,
                    $assistantMessage,
                    $toolContext['map']
                );

                $metadata = $assistantMessage->metadata ?? [];
                $metadata['tool_run_ids'] = $toolRunIds;

                $this->messageService->update($assistantMessage, [
                    'content' => $response->text,
                    'raw_response' => TextResponseSerializer::serialize(
                        $response,
                        fn (ToolResult $result) => $this->recordedToolOutputs[$result->toolCallId] ?? $result->result
                    ),
                    'status' => AiMessageStatus::COMPLETED->value,
                    'failed_reason' => null,
                    'model' => $response->meta->model ?? $state->assistant->model(),
                    'tokens_in' => $response->usage->promptTokens,
                    'tokens_out' => $response->usage->completionTokens,
                    'provider_response_id' => $response->meta->id ?? null,
                    'metadata' => $metadata,
                ]);
            });

            $this->dispatchThreadManagerAssistantJob($assistantMessage);
            $this->dispatchMemoryExtractorAssistantJob($assistantMessage);
        } catch (Throwable $exception) {
            $failedReason = $exception instanceof PrismRateLimitedException
                ? $this->formatRateLimitedFailure($exception, $state ?? null, $textRequest, $providerKey, $modelKey)
                : $exception->getMessage();

            $this->messageService->markStatus($assistantMessage, AiMessageStatus::FAILED, $failedReason);

            throw $exception;
        } finally {
            $this->recordedToolOutputs = [];
        }
    }

    protected function dispatchThreadManagerAssistantJob(AiMessage $assistantMessage): void
    {
        $thread = $assistantMessage->thread?->fresh();

        if ($thread === null) {
            return;
        }

        if ($thread->assistant_key === self::THREAD_MANAGER_KEY) {
            $parent = $thread->parentThread()->first();

            if (! $parent instanceof \Atlas\Nexus\Models\AiThread) {
                return;
            }

            $thread = $parent->fresh() ?? $parent;
        }

        $totalMessageCount = $this->messageService->query()
            ->where('thread_id', $thread->getKey())
            ->where('status', AiMessageStatus::COMPLETED->value)
            ->count();

        $minimumMessages = $this->threadSummaryConfig('minimum_messages', 2);
        $messageInterval = $this->threadSummaryConfig('message_interval', 10);

        if ($totalMessageCount < $minimumMessages) {
            return;
        }

        if ($thread->last_summary_message_id === null) {
            PushThreadManagerAssistantJob::dispatch($thread->getKey());

            return;
        }

        $messagesSinceSummary = $this->messageService->query()
            ->where('thread_id', $thread->getKey())
            ->where('status', AiMessageStatus::COMPLETED->value)
            ->where('id', '>', $thread->last_summary_message_id)
            ->count();

        if ($messagesSinceSummary >= $messageInterval) {
            PushThreadManagerAssistantJob::dispatch($thread->getKey());
        }
    }

    protected function dispatchMemoryExtractorAssistantJob(AiMessage $assistantMessage): void
    {
        $thread = $assistantMessage->thread?->fresh();

        if ($thread === null) {
            return;
        }

        if (in_array($thread->assistant_key, [self::THREAD_MANAGER_KEY, self::MEMORY_EXTRACTOR_KEY], true)) {
            return;
        }

        $pendingCount = $this->messageService->query()
            ->where('thread_id', $thread->getKey())
            ->where('status', AiMessageStatus::COMPLETED->value)
            ->where('is_memory_checked', false)
            ->count();

        $pendingThreshold = $this->memoryExtractorConfig('pending_message_count', 4);

        if ($pendingCount < $pendingThreshold) {
            return;
        }

        $metadata = $thread->metadata ?? [];

        if (! empty($metadata['memory_job_pending'])) {
            return;
        }

        $metadata['memory_job_pending'] = true;

        $this->threadService->update($thread, [
            'metadata' => $metadata,
        ]);

        PushMemoryExtractorAssistantJob::dispatch($thread->getKey());
    }

    protected function memoryExtractorConfig(string $key, int $default): int
    {
        $configuration = config('atlas-nexus.memory_extractor', []);

        if (! is_array($configuration)) {
            return $default;
        }

        $value = (int) ($configuration[$key] ?? $default);

        return $value > 0 ? $value : $default;
    }

    protected function threadSummaryConfig(string $key, int $default): int
    {
        $configuration = config('atlas-nexus.thread_summary', []);

        if (! is_array($configuration)) {
            return $default;
        }

        $value = (int) ($configuration[$key] ?? $default);

        return $value > 0 ? $value : $default;
    }

    /**
     * @return array<int, \Prism\Prism\Contracts\Message>
     */
    protected function convertMessages(ThreadState $state): array
    {
        return $state->messages
            ->map(function (AiMessage $message) {
                return $message->role === AiMessageRole::USER
                    ? new UserMessage($message->content)
                    : new AssistantMessage($message->content);
            })
            ->values()
            ->all();
    }

    /**
     * @return array{tools: array<int, Tool>, map: array<string, ToolDefinition>}
     */
    protected function prepareTools(ThreadState $state, int $assistantMessageId): array
    {
        $toolMap = [];
        $prismTools = [];

        /** @var ToolDefinition $definition */
        foreach ($state->tools as $definition) {
            $handler = $definition->makeHandler();

            if (! $handler instanceof NexusTool) {
                continue;
            }

            if ($handler instanceof ThreadStateAwareTool) {
                $handler->setThreadState($state);
            }

            if ($handler instanceof ConfigurableTool) {
                $configuration = $state->assistant->toolConfiguration($definition->key());

                if ($configuration !== null) {
                    $handler->applyConfiguration($configuration);
                }
            }

            if ($handler instanceof \Atlas\Nexus\Contracts\ToolRunLoggingAware) {
                $handler->setToolRunLogger($this->toolRunLogger);
                $handler->setToolKey($definition->key());
                $handler->setAssistantMessageId($assistantMessageId);
            }

            /** @var Tool $prismTool */
            $prismTool = $handler->toPrismTool()->as($definition->key());
            $prismTools[] = $prismTool;
            $toolMap[$prismTool->name()] = $definition;
        }

        return [
            'tools' => $prismTools,
            'map' => $toolMap,
        ];
    }

    /**
     * @return array<int, ProviderTool>
     */
    protected function prepareProviderTools(ThreadState $state): array
    {
        return $state->providerTools
            ->map(static fn ($definition): ProviderTool => $definition->toPrismProviderTool())
            ->values()
            ->all();
    }

    /**
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     * @param  array<string, ToolDefinition>  $toolMap
     * @return array<int, int>
     */
    protected function recordToolResults(
        array $toolCalls,
        array $toolResults,
        AiMessage $assistantMessage,
        array $toolMap
    ): array {
        $recordedIds = [];
        $runsByCallId = [];

        foreach ($toolCalls as $index => $toolCall) {
            $toolKey = $this->toolKeyFromMap($toolCall->name, $toolMap);
            $callIndex = (int) $index;

            /** @var AiToolRun|null $run */
            $run = $this->findExistingToolRun($assistantMessage->id, $toolKey, $callIndex);

            if ($run === null) {
                $run = $this->toolRunService->create([
                    'tool_key' => $toolKey,
                    'thread_id' => $assistantMessage->thread_id,
                    'group_id' => $assistantMessage->group_id,
                    'assistant_message_id' => $assistantMessage->id,
                    'call_index' => $callIndex,
                    'input_args' => $toolCall->arguments(),
                    'status' => AiToolRunStatus::RUNNING->value,
                    'metadata' => [
                        'tool_call_id' => $toolCall->id,
                        'tool_call_result_id' => $toolCall->resultId ?? null,
                    ],
                ]);
            } else {
                $metadata = $run->metadata ?? [];
                $metadata['tool_call_id'] = $toolCall->id;
                $metadata['tool_call_result_id'] = $toolCall->resultId ?? null;

                $this->toolRunService->update($run, [
                    'metadata' => $metadata,
                ]);
            }

            $runsByCallId[$toolCall->id] = $run;
        }

        foreach ($toolResults as $index => $toolResult) {
            $toolKey = $this->toolKeyFromMap($toolResult->toolName, $toolMap);
            $run = $runsByCallId[$toolResult->toolCallId] ?? null;
            $callIndex = (int) $index;

            if ($run === null) {
                $run = $this->findExistingToolRun($assistantMessage->id, $toolKey, $callIndex);
            }

            if ($run === null) {
                $run = $this->toolRunService->create([
                    'tool_key' => $toolKey,
                    'thread_id' => $assistantMessage->thread_id,
                    'group_id' => $assistantMessage->group_id,
                    'assistant_message_id' => $assistantMessage->id,
                    'call_index' => $callIndex,
                    'input_args' => $toolResult->args,
                    'status' => AiToolRunStatus::RUNNING->value,
                    'response_output' => null,
                    'metadata' => [
                        'tool_call_id' => $toolResult->toolCallId,
                        'tool_call_result_id' => $toolResult->toolCallResultId,
                    ],
                ]);
            }

            $responseOutput = $run->response_output ?? $this->normalizeToolResultOutput($toolResult->result);
            $metadata = $run->metadata ?? [];
            $metadata['tool_call_id'] = $toolResult->toolCallId;
            $metadata['tool_call_result_id'] = $toolResult->toolCallResultId;

            $run = $this->toolRunService->update($run, [
                'status' => AiToolRunStatus::SUCCEEDED->value,
                'response_output' => $responseOutput,
                'metadata' => $metadata,
                'started_at' => $run->started_at ?? Carbon::now(),
                'finished_at' => Carbon::now(),
            ]);

            $this->recordedToolOutputs[$toolResult->toolCallId] = $responseOutput;
            $recordedIds[] = (int) $run->id;
        }

        return $recordedIds;
    }

    protected function findExistingToolRun(int $assistantMessageId, string $toolKey, int $callIndex): ?AiToolRun
    {
        /** @var AiToolRun|null $run */
        $run = $this->toolRunService->query()
            ->where('assistant_message_id', $assistantMessageId)
            ->where('tool_key', $toolKey)
            ->where('call_index', $callIndex)
            ->orderByDesc('id')
            ->first();

        return $run;
    }

    /**
     * @param  array<string, mixed>|int|float|string|null  $result
     * @return array<string, mixed>|null
     */
    protected function normalizeToolResultOutput(int|float|string|array|null $result): ?array
    {
        if ($result === null) {
            return null;
        }

        return is_array($result) ? $result : ['result' => $result];
    }

    /**
     * @param  array<string, ToolDefinition>  $toolMap
     */
    protected function toolKeyFromMap(string $name, array $toolMap): string
    {
        $definition = $toolMap[$name] ?? null;

        if ($definition instanceof ToolDefinition) {
            return $definition->key();
        }

        return $name;
    }

    protected function resolveProvider(): string
    {
        $provider = config('prism.default_provider', 'openai');

        return is_string($provider) && $provider !== '' ? $provider : 'openai';
    }

    protected function resolveModel(ThreadState $state): string
    {
        $model = $state->assistant->model();

        if (! is_string($model) || $model === '') {
            $model = config('prism.default_model');
        }

        return is_string($model) && $model !== '' ? $model : 'gpt-4o-mini';
    }

    protected function resolveMaxSteps(ThreadState $state): int
    {
        $assistantMax = $state->assistant->maxDefaultSteps();

        if (is_int($assistantMax) && $assistantMax > 0) {
            return $assistantMax;
        }

        $configured = (int) config('prism.max_steps', 8);

        return $configured > 0 ? $configured : 1;
    }

    /**
     * @return array<string, mixed>
     */
    protected function resolveProviderOptions(ThreadState $state, string $provider): array
    {
        $options = [];

        if (strtolower($provider) === 'openai') {
            $reasoning = $state->assistant->reasoning();

            if (is_array($reasoning) && $reasoning !== []) {
                $options['reasoning'] = $reasoning;
            }
        }

        return $options;
    }

    protected function formatRateLimitedFailure(
        PrismRateLimitedException $exception,
        ?ThreadState $state,
        ?TextRequest $textRequest,
        ?string $provider,
        ?string $model
    ): string {
        $details = [
            'provider rate limit hit',
            'provider='.($provider ?? 'unknown'),
            'model='.($model ?? 'unknown'),
        ];

        if ($exception->retryAfter !== null) {
            $details[] = 'retry_after='.$exception->retryAfter.'s';
        }

        $limitDetails = [];

        foreach ($exception->rateLimits as $limit) {
            $limitDetails[] = sprintf(
                '%s(limit=%s, remaining=%s, resets_at=%s)',
                $limit->name,
                $limit->limit ?? 'unknown',
                $limit->remaining ?? 'unknown',
                $limit->resetsAt?->toIso8601String() ?? 'unknown'
            );
        }

        $details[] = $limitDetails === []
            ? 'limits=unavailable'
            : 'limits=['.implode(', ', $limitDetails).']';

        if ($this->shouldFetchOpenAiLimits($provider)) {
            $accountLimits = $this->openAiRateLimitClient->fetchLimits();

            if ($accountLimits !== null) {
                $details[] = 'openai_account_limits='.$accountLimits->describe();
            }
        }

        if ($state !== null) {
            $details[] = sprintf(
                'context={assistant_key=%s, assistant_name=%s, thread_id=%s, messages=%d, tools=%d, memories=%d}',
                $state->assistant->key(),
                $state->assistant->name(),
                (string) $state->thread->getKey(),
                $state->messages->count(),
                $state->tools->count(),
                $state->memories->count()
            );
        }

        if ($textRequest !== null) {
            $details[] = sprintf(
                'request={system_prompts=%d, provider_tools=%d}',
                count($textRequest->toRequest()->systemPrompts()),
                count($textRequest->toRequest()->providerTools())
            );
        }

        return implode('; ', $details);
    }

    protected function shouldFetchOpenAiLimits(?string $provider): bool
    {
        if ($provider === null) {
            return false;
        }

        return strtolower($provider) === 'openai';
    }
}
