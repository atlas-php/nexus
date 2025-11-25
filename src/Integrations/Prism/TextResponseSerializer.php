<?php

declare(strict_types=1);

namespace Atlas\Nexus\Integrations\Prism;

use Prism\Prism\Text\Response;
use Prism\Prism\Text\Step;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Meta;
use Prism\Prism\ValueObjects\ProviderRateLimit;
use Prism\Prism\ValueObjects\ProviderToolCall;
use Prism\Prism\ValueObjects\ToolCall;
use Prism\Prism\ValueObjects\ToolResult;
use Prism\Prism\ValueObjects\Usage;

/**
 * Class TextResponseSerializer
 *
 * Converts Prism text responses into structured arrays for persistence and inspection.
 */
class TextResponseSerializer
{
    /**
     * @param  null|callable(ToolResult): (array<string, mixed>|int|float|string|null)  $toolResultResolver
     * @return array<string, mixed>
     */
    public static function serialize(Response $response, ?callable $toolResultResolver = null): array
    {
        return [
            'text' => $response->text,
            'finish_reason' => $response->finishReason->value,
            'model' => $response->meta->model,
            'provider_response_id' => $response->meta->id,
            'usage' => self::serializeUsage($response->usage),
            'tool_calls' => array_map(static fn (ToolCall $call): array => self::serializeToolCall($call), $response->toolCalls),
            'tool_results' => array_map(
                static fn (ToolResult $result): array => self::serializeToolResult($result, $toolResultResolver),
                $response->toolResults
            ),
            'steps' => $response->steps->map(
                static fn (Step $step): array => self::serializeStep($step, $toolResultResolver)
            )->all(),
            'additional_content' => $response->additionalContent,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializeStep(Step $step, ?callable $toolResultResolver = null): array
    {
        return [
            'text' => $step->text,
            'finish_reason' => $step->finishReason->value,
            'tool_calls' => array_map(static fn (ToolCall $call): array => self::serializeToolCall($call), $step->toolCalls),
            'tool_results' => array_map(
                static fn (ToolResult $result): array => self::serializeToolResult($result, $toolResultResolver),
                $step->toolResults
            ),
            'provider_tool_calls' => array_map(
                static fn (ProviderToolCall $call): array => self::serializeProviderToolCall($call),
                $step->providerToolCalls
            ),
            'meta' => self::serializeMeta($step->meta),
            'system_prompts' => array_map(static fn (SystemMessage $message): string => $message->content, $step->systemPrompts),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function serializeToolCall(ToolCall $toolCall): array
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
    public static function serializeToolResult(ToolResult $toolResult, ?callable $resolver = null): array
    {
        $result = $resolver === null
            ? $toolResult->result
            : $resolver($toolResult);

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
    public static function serializeProviderToolCall(ProviderToolCall $providerToolCall): array
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
    public static function serializeUsage(Usage $usage): array
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
    public static function serializeMeta(Meta $meta): array
    {
        return [
            'id' => $meta->id,
            'model' => $meta->model,
            'rate_limits' => array_map(
                static fn (ProviderRateLimit $limit): array => [
                    'name' => $limit->name,
                    'limit' => $limit->limit,
                    'remaining' => $limit->remaining,
                    'resets_at' => $limit->resetsAt?->toIso8601String(),
                ],
                $meta->rateLimits
            ),
        ];
    }
}
