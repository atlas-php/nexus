<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Threads;

use Atlas\Nexus\Contracts\NexusTool;
use Atlas\Nexus\Contracts\ThreadStateAwareTool;
use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Enums\AiToolRunStatus;
use Atlas\Nexus\Integrations\Prism\TextRequestFactory;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiTool;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Models\AiToolRunService;
use Atlas\Nexus\Services\Models\AiToolService;
use Atlas\Nexus\Services\Tools\ToolRunLogger;
use Atlas\Nexus\Support\Chat\ChatThreadLog;
use Atlas\Nexus\Support\Chat\ThreadState;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
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
    public function __construct(
        private readonly ThreadStateService $threadStateService,
        private readonly AiMessageService $messageService,
        private readonly AiToolRunService $toolRunService,
        private readonly AiToolService $toolService,
        private readonly TextRequestFactory $textRequestFactory,
        private readonly ToolRunLogger $toolRunLogger
    ) {}

    public function handle(int $assistantMessageId): void
    {
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

            $request = $textRequest
                ->using($this->resolveProvider(), $this->resolveModel($state))
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

            if ($state->prompt !== null) {
                $request->withSystemPrompt($state->prompt->system_prompt);
            }

            $memoryPrompt = $this->memoryPrompt($state);

            if ($memoryPrompt !== null) {
                $request->withSystemPrompt($memoryPrompt);
            }

            if ($toolContext['tools'] !== []) {
                $request->withTools($toolContext['tools']);
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
            $this->messageService->markStatus($assistantMessage, AiMessageStatus::FAILED, $exception->getMessage());

            throw $exception;
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
     * @return array{tools: array<int, Tool>, map: array<string, AiTool>}
     */
    protected function prepareTools(ThreadState $state, int $assistantMessageId): array
    {
        $toolMap = [];
        $prismTools = [];

        /** @var AiTool $tool */
        foreach ($state->tools as $tool) {
            $handlerClass = $tool->handler_class;

            if (! class_exists($handlerClass)) {
                continue;
            }

            $handler = app($handlerClass);

            if (! $handler instanceof NexusTool) {
                continue;
            }

            if ($handler instanceof ThreadStateAwareTool) {
                $handler->setThreadState($state);
            }

            if ($handler instanceof \Atlas\Nexus\Contracts\ToolRunLoggingAware) {
                $handler->setToolRunLogger($this->toolRunLogger);
                $handler->setToolModel($tool);
                $handler->setAssistantMessageId($assistantMessageId);
            }

            /** @var Tool $prismTool */
            $prismTool = $handler->toPrismTool()->as($tool->slug);
            $prismTools[] = $prismTool;
            $toolMap[$prismTool->name()] = $tool;
        }

        return [
            'tools' => $prismTools,
            'map' => $toolMap,
        ];
    }

    /**
     * @param  ToolCall[]  $toolCalls
     * @param  ToolResult[]  $toolResults
     * @param  array<string, AiTool>  $toolMap
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
            $tool = $toolMap[$toolCall->name]
                ?? $this->toolService->query()->withTrashed()->where('slug', $toolCall->name)->first();

            if ($tool !== null && $tool->trashed()) {
                $tool->restore();
            }

            if ($tool === null) {
                continue;
            }

            $run = $this->toolRunService->create([
                'tool_id' => $tool->id,
                'thread_id' => $assistantMessage->thread_id,
                'assistant_message_id' => $assistantMessage->id,
                'call_index' => (int) $index,
                'input_args' => $toolCall->arguments(),
                'status' => AiToolRunStatus::RUNNING->value,
                'metadata' => [
                    'tool_call_id' => $toolCall->id,
                    'tool_call_result_id' => $toolCall->resultId ?? null,
                ],
            ]);

            $runsByCallId[$toolCall->id] = $run;
        }

        foreach ($toolResults as $index => $toolResult) {
            $tool = $toolMap[$toolResult->toolName]
                ?? $this->toolService->query()->withTrashed()->where('slug', $toolResult->toolName)->first();

            if ($tool !== null && $tool->trashed()) {
                $tool->restore();
            }

            if ($tool === null) {
                continue;
            }

            $run = $runsByCallId[$toolResult->toolCallId] ?? null;

            if ($run === null) {
                $run = $this->toolRunService->create([
                    'tool_id' => $tool->id,
                    'thread_id' => $assistantMessage->thread_id,
                    'assistant_message_id' => $assistantMessage->id,
                    'call_index' => (int) $index,
                    'input_args' => $toolResult->args,
                    'status' => AiToolRunStatus::RUNNING->value,
                    'response_output' => null,
                    'metadata' => [
                        'tool_call_id' => $toolResult->toolCallId,
                        'tool_call_result_id' => $toolResult->toolCallResultId,
                    ],
                ]);
            }

            $responseOutput = is_array($toolResult->result)
                ? $toolResult->result
                : ['result' => $toolResult->result];

            $metadata = [
                'tool_call_id' => $toolResult->toolCallId,
                'tool_call_result_id' => $toolResult->toolCallResultId,
            ];

            $this->toolRunService->update($run, [
                'status' => AiToolRunStatus::SUCCEEDED->value,
                'response_output' => $responseOutput,
                'metadata' => $metadata,
                'started_at' => $run->started_at ?? Carbon::now(),
                'finished_at' => Carbon::now(),
            ]);

            $recordedIds[] = (int) $run->id;
        }

        return $recordedIds;
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

    protected function memoryPrompt(ThreadState $state): ?string
    {
        if ($state->memories->isEmpty()) {
            return null;
        }

        $lines = $state->memories
            ->map(fn ($memory): string => sprintf(
                '- (%s) %s',
                $memory->kind,
                $memory->content
            ))
            ->all();

        return "Contextual memories:\n".implode("\n", $lines);
    }
}
