<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Fixtures;

use Atlas\Nexus\Integrations\Prism\Tools\AbstractTool;
use Atlas\Nexus\Integrations\Prism\Tools\ToolResponse;

/**
 * Provides a deterministic Prism tool for exercising tool availability and logging.
 */
class StubTool extends AbstractTool
{
    public function name(): string
    {
        return 'calendar_lookup';
    }

    public function description(): string
    {
        return 'Returns mock calendar data for testing.';
    }

    public function handle(array $arguments): ToolResponse
    {
        return $this->output('ok', ['arguments' => $arguments]);
    }
}
