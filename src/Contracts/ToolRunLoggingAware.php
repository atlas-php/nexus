<?php

declare(strict_types=1);

namespace Atlas\Nexus\Contracts;

use Atlas\Nexus\Models\AiTool;
use Atlas\Nexus\Services\Tools\ToolRunLogger;

/**
 * Interface ToolRunLoggingAware
 *
 * Allows tools to receive logging context for creating and completing tool run records.
 */
interface ToolRunLoggingAware
{
    public function setToolRunLogger(ToolRunLogger $logger): void;

    public function setToolModel(AiTool $tool): void;

    public function setAssistantMessageId(?int $assistantMessageId): void;
}
