<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Contracts\NexusTool;
use Atlas\Nexus\Contracts\ThreadStateAwareTool;
use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Enums\AiToolRunStatus;
use Atlas\Nexus\Integrations\Prism\TextRequest;
use Atlas\Nexus\Integrations\Prism\TextRequestFactory;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiToolRun;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Models\AiToolRunService;
use Atlas\Nexus\Services\Tools\ToolRunLogger;
use Atlas\Nexus\Support\Chat\ChatThreadLog;
use Atlas\Nexus\Support\Chat\ThreadState;
use Atlas\Nexus\Support\Tools\ToolDefinition;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Prism\Prism\Contracts\Message;
use Prism\Prism\Exceptions\PrismRateLimitedException;
use Prism\Prism\Text\Response;
use Prism\Prism\Text\Step;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Prism\Prism\ValueObjects\ProviderTool;
use Prism\Prism\ValueObjects\ProviderToolCall;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;
use RuntimeException;
use Throwable;

/**
 * Class AssistantResponseService
 *
 * Generates assistant responses for a thread, handling Prism calls, tool logging, and failure tracking for both queued and inline execution.
 */
class AssistantResponseService
{
    /**
     * @var array<string, array<string, mixed>|int|float|string|null>
     */
    protected array $recordedToolOutputs = [];

    public function __construct(
        private readonly ThreadStateService $threadStateService,
        private readonly AiMessageService $messageService,
        private readonly AiToolRunService $toolRunService,
        private readonly TextRequestFactory $textRequestFactory,
        private readonly ToolRunLogger $toolRunLogger
    ) {}

    public function handle(int $assistantMessageId): void
    {
        $this->recordedToolOutputs = [];
        $textRequest = null;
        $providerKey = null;
        $modelKey = null;

        /** @var AiMessage $assistantMessage */
        $assistantMessage = $this->messageService->findOrFail($assistantMessageId);
        $assistantMessage->loadMissing('thread.assistant');

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
                ->withMaxSteps($this->maxSteps());

            if ($state->assistant->max_output_tokens !== null) {
                $request->withMaxTokens($state->assistant->max_output_tokens);
            }

            if ($state->assistant->temperature !== null) {
                $request->usingTemperature($state->assistant->temperature);
            }

            if ($state->assistant->top_p !== null) {
                $request->usingTopP($state->assistant->top_p);
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
                $metadata['memory_ids'] = $state->memories->pluck('id')->all();
                $metadata['tool_run_ids'] = $toolRunIds;

                $this->messageService->update($assistantMessage, [
                    'content' => $response->text,
                    'raw_response' => $this->serializeResponse($response),
                    'status' => AiMessageStatus::COMPLETED->value,
                    'failed_reason' => null,
                    'model' => $response->meta->model ?? $state->assistant->default_model,
                    'tokens_in' => $response->usage->promptTokens,
                    'tokens_out' => $response->usage->completionTokens,
                    'provider_response_id' => $response->meta->id ?? null,
                    'metadata' => $metadata,
                ]);
            });
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

    /**
     * @return array<string, mixed>
     */
    protected function serializeResponse(Response $response): array
    {
        return [
            'text' => $response->text,
            'finish_reason' => $response->finishReason->value,
            'model' => $response->meta->model,
            'provider_response_id' => $response->meta->id,
            'usage' => $this->serializeUsage($response->usage),
            'tool_calls' => array_map(fn (ToolCall $call): array => $this->serializeToolCall($call), $response->toolCalls),
            'tool_results' => array_map(fn (ToolResult $result): array => $this->serializeToolResult($result), $response->toolResults),
            // 'messages' => $this->serializeMessages($response->messages->all()),
            'steps' => $response->steps->map(fn (Step $step): array => $this->serializeStep($step))->all(),
            'additional_content' => $response->additionalContent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeStep(Step $step): array
    {
        return [
            'text' => $step->text,
            'finish_reason' => $step->finishReason->value,
            'tool_calls' => array_map(fn (ToolCall $call): array => $this->serializeToolCall($call), $step->toolCalls),
            'tool_results' => array_map(fn (ToolResult $result): array => $this->serializeToolResult($result), $step->toolResults),
            'provider_tool_calls' => array_map(fn (ProviderToolCall $call): array => $this->serializeProviderToolCall($call), $step->providerToolCalls),
            // 'usage' => $this->serializeUsage($step->usage),
            'meta' => $this->serializeMeta($step->meta),
            // 'messages' => $this->serializeMessages($step->messages),
            'system_prompts' => array_map(static fn (SystemMessage $message): string => $message->content, $step->systemPrompts),
            // 'additional_content' => $step->additionalContent,
        ];
    }

    /**
     * @param  array<int, Message>  $messages
     * @return array<int, array<string, mixed>>
     */
    protected function serializeMessages(array $messages): array
    {
        return array_map(function (Message $message): array {
            if ($message instanceof UserMessage) {
                return [
                    'type' => 'user',
                    'content' => $message->text(),
                    'additional_content' => $message->additionalContent,
                    'provider_options' => $message->providerOptions(),
                ];
            }

            if ($message instanceof AssistantMessage) {
                return [
                    'type' => 'assistant',
                    'content' => $message->content,
                    'tool_calls' => array_map(fn (ToolCall $call): array => $this->serializeToolCall($call), $message->toolCalls),
                    'additional_content' => $message->additionalContent,
                    'provider_options' => $message->providerOptions(),
                ];
            }

            if ($message instanceof ToolResultMessage) {
                return [
                    'type' => 'tool_result',
                    'tool_results' => array_map(fn (ToolResult $result): array => $this->serializeToolResult($result), $message->toolResults),
                    'provider_options' => $message->providerOptions(),
                ];
            }

            if ($message instanceof SystemMessage) {
                return [
                    'type' => 'system',
                    'content' => $message->content,
                    'provider_options' => $message->providerOptions(),
                ];
            }

            return [
                'type' => get_debug_type($message),
            ];
        }, $messages);
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeToolCall(ToolCall $toolCall): array
    {
        return [
            'id' => $toolCall->id,
            'name' => $toolCall->name,
            'arguments' => $toolCall->arguments(),
            'result_id' => $toolCall->resultId,
            'reasoning_id' => $toolCall->reasoningId,
            'reasoning_summary' => $toolCall->reasoningSummary,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeToolResult(ToolResult $toolResult): array
    {
        $result = $this->recordedToolOutputs[$toolResult->toolCallId] ?? $toolResult->result;

        return [
            'tool_call_id' => $toolResult->toolCallId,
            'tool_name' => $toolResult->toolName,
            'args' => $toolResult->args,
            'result' => $result,
            'tool_call_result_id' => $toolResult->toolCallResultId,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeProviderToolCall(ProviderToolCall $providerToolCall): array
    {
        return [
            'id' => $providerToolCall->id,
            'type' => $providerToolCall->type,
            'status' => $providerToolCall->status,
            'data' => $providerToolCall->data,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeUsage(Usage $usage): array
    {
        return [
            'prompt_tokens' => $usage->promptTokens,
            'completion_tokens' => $usage->completionTokens,
            'cache_write_input_tokens' => $usage->cacheWriteInputTokens,
            'cache_read_input_tokens' => $usage->cacheReadInputTokens,
            'thought_tokens' => $usage->thoughtTokens,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function serializeMeta(Meta $meta): array
    {
        return [
            'id' => $meta->id,
            'model' => $meta->model,
            'rate_limits' => array_map(fn (ProviderRateLimit $limit): array => [
                'name' => $limit->name,
                'limit' => $limit->limit,
                'remaining' => $limit->remaining,
                'resets_at' => $limit->resetsAt?->toIso8601String(),
            ], $meta->rateLimits),
        ];
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
        $model = $state->assistant->default_model;

        if (! is_string($model) || $model === '') {
            $model = config('prism.default_model');
        }

        return is_string($model) && $model !== '' ? $model : 'gpt-4o-mini';
    }

    protected function maxSteps(): int
    {
        return (int) config('prism.max_steps', 8);
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

        if ($state !== null) {
            $details[] = sprintf(
                'context={assistant=%s, thread_id=%s, messages=%d, tools=%d, memories=%d}',
                $state->assistant->slug ?? ('#'.$state->assistant->getKey()),
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
}
