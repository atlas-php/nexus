<?php

declare(strict_types=1);

namespace Atlas\Nexus\Integrations\Prism\Tools;

use Atlas\Nexus\Contracts\NexusTool;
use Prism\Prism\Tool as PrismTool;

/**
 * Class AbstractTool
 *
 * Provides a base Nexus tool that maps domain logic to Prism's tool contract with consistent output handling.
 */
abstract class AbstractTool implements NexusTool
{
    /**
     * @return array<int, ToolParameter>
     */
    public function parameters(): array
    {
        return [];
    }

    public function toPrismTool(): PrismTool
    {
        $tool = (new PrismTool)
            ->as($this->name())
            ->for($this->description())
            ->using(function (mixed ...$arguments): string {
                $response = $this->handle($this->normalizeArguments($arguments));

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
     * @param  array<int|string, mixed>  $arguments
     * @return array<string, mixed>
     */
    protected function normalizeArguments(array $arguments): array
    {
        if (count($arguments) === 1 && is_array($arguments[0])) {
            $firstArgument = $arguments[0];

            return array_is_list($firstArgument) ? $this->mapPositionalArguments($firstArgument) : $this->stringifyKeys($firstArgument);
        }

        if (array_is_list($arguments)) {
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
