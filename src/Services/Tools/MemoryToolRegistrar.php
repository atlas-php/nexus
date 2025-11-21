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
            ->withTrashed()
            ->where('slug', MemoryTool::SLUG)
            ->first();

        if ($memoryTool === null) {
            $memoryTool = $this->toolService->create(MemoryTool::toolRecordDefinition());
        } elseif ($memoryTool->trashed()) {
            $memoryTool->restore();
        } elseif (! $memoryTool->is_active || $memoryTool->handler_class !== MemoryTool::class) {
            $this->toolService->update($memoryTool, [
                'handler_class' => MemoryTool::class,
                'is_active' => true,
                'schema' => MemoryTool::toolSchema(),
            ]);
        }

        $this->assistantService->attachTool($assistant, $memoryTool, [
            'built_in' => true,
        ]);

        return $memoryTool;
    }
}
