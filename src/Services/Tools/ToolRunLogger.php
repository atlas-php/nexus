<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Tools;

use Atlas\Nexus\Enums\AiToolRunStatus;
use Atlas\Nexus\Models\AiToolRun;
use Atlas\Nexus\Services\Models\AiToolRunService;
use Atlas\Nexus\Services\Threads\Data\ThreadState;
use Illuminate\Support\Carbon;

/**
 * Class ToolRunLogger
 *
 * Records tool execution lifecycle entries directly during tool handler execution.
 */
class ToolRunLogger
{
    public function __construct(
        private readonly AiToolRunService $toolRunService
    ) {}

    /**
     * Start a tool run record using the provided context.
     *
     * @param  array<string, mixed>  $arguments
     */
    public function start(
        string $toolKey,
        ThreadState $state,
        int $assistantMessageId,
        int $callIndex,
        array $arguments,
        ?string $toolCallId = null
    ): AiToolRun {
        /** @var AiToolRun $run */
        $run = $this->toolRunService->create([
            'tool_key' => $toolKey,
            'thread_id' => $state->thread->id,
            'group_id' => $state->thread->group_id,
            'assistant_message_id' => $assistantMessageId,
            'call_index' => $callIndex,
            'input_args' => $arguments,
            'status' => AiToolRunStatus::RUNNING->value,
            'metadata' => [
                'tool_call_id' => $toolCallId,
            ],
            'started_at' => Carbon::now(),
        ]);

        return $run;
    }

    /**
     * Complete a tool run record.
     *
     * @param  array<string, mixed>|int|float|string|null  $result
     */
    public function complete(AiToolRun $run, int|string|float|array|null $result, ?string $toolCallResultId = null): AiToolRun
    {
        $responseOutput = is_array($result)
            ? $result
            : ['result' => $result];

        $metadata = $run->metadata ?? [];
        $metadata['tool_call_result_id'] = $toolCallResultId;

        return $this->toolRunService->update($run, [
            'status' => AiToolRunStatus::SUCCEEDED->value,
            'response_output' => $responseOutput,
            'metadata' => $metadata,
            'finished_at' => Carbon::now(),
        ]);
    }

    /**
     * Mark a tool run as failed with an error message and optional result payload.
     *
     * @param  array<string, mixed>|int|float|string|null  $result
     */
    public function fail(AiToolRun $run, string $errorMessage, int|string|float|array|null $result = null, ?string $toolCallResultId = null): AiToolRun
    {
        $responseOutput = is_array($result)
            ? $result
            : ($result === null ? null : ['result' => $result]);

        $metadata = $run->metadata ?? [];
        $metadata['tool_call_result_id'] = $toolCallResultId;

        return $this->toolRunService->update($run, [
            'status' => AiToolRunStatus::FAILED->value,
            'response_output' => $responseOutput,
            'metadata' => $metadata,
            'error_message' => $errorMessage,
            'finished_at' => Carbon::now(),
        ]);
    }
}
