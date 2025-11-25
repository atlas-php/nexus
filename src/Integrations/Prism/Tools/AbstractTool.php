<?php

declare(strict_types=1);

namespace Atlas\Nexus\Integrations\Prism\Tools;

use Atlas\Nexus\Contracts\NexusTool;
use Atlas\Nexus\Contracts\ToolRunLoggingAware;
use Atlas\Nexus\Models\AiToolRun;
use Atlas\Nexus\Services\Tools\ToolRunLogger;
use Prism\Prism\Tool as PrismTool;
use Throwable;

/**
 * Class AbstractTool
 *
 * Provides a base Nexus tool that maps domain logic to Prism's tool contract with consistent output handling.
 */
abstract class AbstractTool implements NexusTool, ToolRunLoggingAware
{
    protected ?string $toolKey = null;

    protected ?ToolRunLogger $toolRunLogger = null;

    protected ?int $assistantMessageId = null;

    protected int $callCounter = 0;

    /**
     * @return array<int, ToolParameter>
     */
    public function parameters(): array
    {
        return [];
    }

    public function setToolRunLogger(ToolRunLogger $logger): void
    {
        $this->toolRunLogger = $logger;
    }

    public function setToolKey(string $toolKey): void
    {
        $this->toolKey = $toolKey;
    }

    public function setAssistantMessageId(?int $assistantMessageId): void
    {
        $this->assistantMessageId = $assistantMessageId;
    }

    public function toPrismTool(): PrismTool
    {
        $tool = (new PrismTool)
            ->as($this->name())
            ->for($this->description())
            ->using(function (mixed ...$arguments): string {
                $normalizedArguments = $this->normalizeArguments($arguments);
                $run = $this->logRunStart($normalizedArguments);
                $response = null;

                try {
                    $response = $this->handle($normalizedArguments);
                    $this->logRunComplete($run, $response);
                } catch (Throwable $exception) {
                    $response = $this->handleFailure($run, $exception);
                }

                return $response->message();
            });

        foreach ($this->parameters() as $parameter) {
            $tool->withParameter($parameter->schema(), $parameter->isRequired());
        }

        return $tool;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function output(string $message, array $meta = []): ToolResponse
    {
        return new ToolResponse($message, $meta);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    protected function logRunStart(array $arguments): ?\Atlas\Nexus\Models\AiToolRun
    {
        if ($this->toolRunLogger === null || $this->toolKey === null || ! property_exists($this, 'state') || $this->assistantMessageId === null) {
            return null;
        }

        /** @var mixed $state */
        $state = $this->state ?? null;

        if (! $state instanceof \Atlas\Nexus\Services\Threads\Data\ThreadState) {
            return null;
        }

        return $this->toolRunLogger->start(
            $this->toolKey,
            $state,
            $this->assistantMessageId,
            $this->nextCallIndex(),
            $arguments
        );
    }

    protected function logRunComplete(?\Atlas\Nexus\Models\AiToolRun $run, ToolResponse $response): void
    {
        if ($run === null || $this->toolRunLogger === null) {
            return;
        }

        $this->toolRunLogger->complete($run, $response->meta()['result'] ?? $response->meta() ?: $response->message());
    }

    protected function handleFailure(?AiToolRun $run, Throwable $exception): ToolResponse
    {
        if ($run !== null && $this->toolRunLogger !== null) {
            $this->toolRunLogger->fail($run, $exception->getMessage());
        }

        return $this->output(
            'Tool execution error: '.$exception->getMessage(),
            [
                'error' => true,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]
        );
    }

    protected function nextCallIndex(): int
    {
        return $this->callCounter++;
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function normalizeArguments(array $arguments): array
    {
        $hasZeroIndex = array_key_exists(0, $arguments);

        if ($hasZeroIndex && count($arguments) === 1 && is_array($arguments[0])) {
            $firstArgument = $arguments[0];

            return array_is_list($firstArgument)
                ? $this->mapPositionalArguments($firstArgument)
                : $this->stringifyKeys($firstArgument);
        }

        if ($hasZeroIndex && array_is_list($arguments)) {
            return $this->mapPositionalArguments($arguments);
        }

        return $this->stringifyKeys($arguments);
    }

    /**
     * @param  array<int, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function mapPositionalArguments(array $arguments): array
    {
        $parameters = $this->parameters();
        $parameterCount = count($parameters);

        if ($parameterCount === 0) {
            return [];
        }

        $mapped = [];

        foreach ($arguments as $index => $value) {
            if (! array_key_exists($index, $parameters)) {
                continue;
            }

            $schema = $parameters[$index]->schema();
            $mapped[$schema->name()] = $value;
        }

        return $mapped;
    }

    /**
     * @param  array<int|string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function stringifyKeys(array $arguments): array
    {
        $normalized = [];

        foreach ($arguments as $key => $value) {
            $normalized[(string) $key] = $value;
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    abstract public function handle(array $arguments): ToolResponse;

    abstract public function name(): string;

    abstract public function description(): string;
}
