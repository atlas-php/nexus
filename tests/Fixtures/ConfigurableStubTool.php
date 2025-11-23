<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Fixtures;

use Atlas\Nexus\Contracts\ConfigurableTool;
use Atlas\Nexus\Integrations\Prism\Tools\AbstractTool;
use Atlas\Nexus\Integrations\Prism\Tools\ToolResponse;

/**
 * Configurable tool stub that records the applied configuration for assertions.
 */
class ConfigurableStubTool extends AbstractTool implements ConfigurableTool
{
    /**
     * @var array<string, mixed>|null
     */
    public static ?array $appliedConfiguration = null;

    public function name(): string
    {
        return 'configurable_tool';
    }

    public function description(): string
    {
        return 'Configurable stub tool.';
    }

    public function applyConfiguration(array $configuration): void
    {
        self::$appliedConfiguration = $configuration;
    }

    public static function reset(): void
    {
        self::$appliedConfiguration = null;
    }

    public function handle(array $arguments): ToolResponse
    {
        return $this->output('ok', ['arguments' => $arguments]);
    }
}
