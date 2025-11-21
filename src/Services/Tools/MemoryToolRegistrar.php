<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Tools;

use Atlas\Nexus\Integrations\Prism\Tools\MemoryTool;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiTool;
use Atlas\Nexus\Services\Models\AiAssistantService;
use Atlas\Nexus\Services\Models\AiToolService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Class MemoryToolRegistrar
 *
 * Ensures the built-in memory tool exists and is attached to assistants when memory support is enabled.
 */
class MemoryToolRegistrar
{
    public function __construct(
        private readonly AiToolService $toolService,
        private readonly AiAssistantService $assistantService,
        private readonly ConfigRepository $config
    ) {}

    public function ensureRegisteredForAssistant(AiAssistant $assistant): ?AiTool
    {
        if (! $this->config->get('atlas-nexus.tools.memory.enabled', true)) {
            return null;
        }

        /** @var AiTool|null $memoryTool */
        $memoryTool = $this->toolService->query()
            ->where('slug', MemoryTool::SLUG)
            ->where('handler_class', MemoryTool::class)
            ->where('is_active', true)
            ->first();

        if ($memoryTool === null) {
            return null;
        }

        $this->assistantService->attachTool($assistant, $memoryTool, [
            'built_in' => true,
        ]);

        return $memoryTool;
    }
}
