<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Seeders;

use Atlas\Nexus\Contracts\NexusSeeder;
use Atlas\Nexus\Integrations\Prism\Tools\MemoryTool;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiTool;
use Atlas\Nexus\Services\Models\AiAssistantService;
use Atlas\Nexus\Services\Models\AiToolService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Collection;

/**
 * Class MemoryFeatureSeeder
 *
 * Seeds the built-in memory tool and ensures existing assistants are linked to it.
 */
class MemoryFeatureSeeder implements NexusSeeder
{
    public function __construct(
        private readonly AiToolService $toolService,
        private readonly AiAssistantService $assistantService,
        private readonly ConfigRepository $config
    ) {}

    public function seed(): void
    {
        if (! $this->config->get('atlas-nexus.tools.memory.enabled', true)) {
            return;
        }

        $memoryTool = $this->ensureMemoryTool();

        if ($memoryTool === null || ! $memoryTool->is_active) {
            return;
        }

        $this->attachToAssistants($memoryTool);
    }

    protected function ensureMemoryTool(): ?AiTool
    {
        /** @var AiTool|null $memoryTool */
        $memoryTool = $this->toolService->query()
            ->withTrashed()
            ->where('slug', MemoryTool::SLUG)
            ->first();

        if ($memoryTool === null) {
            /** @var AiTool $created */
            $created = $this->toolService->create(MemoryTool::toolRecordDefinition());

            return $created;
        }

        if ($memoryTool->trashed()) {
            $memoryTool->restore();
        }

        if ($memoryTool->handler_class !== MemoryTool::class || ! $memoryTool->is_active) {
            $this->toolService->update($memoryTool, [
                'handler_class' => MemoryTool::class,
                'schema' => MemoryTool::toolSchema(),
                'is_active' => true,
            ]);
        }

        return $memoryTool;
    }

    protected function attachToAssistants(AiTool $memoryTool): void
    {
        /** @var Collection<int, AiAssistant> $assistants */
        $assistants = $this->assistantService->query()->get();

        $assistants->each(function (AiAssistant $assistant) use ($memoryTool): void {
            $this->assistantService->attachTool($assistant, $memoryTool, [
                'built_in' => true,
            ]);
        });
    }
}
