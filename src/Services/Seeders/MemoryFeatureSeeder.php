<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Seeders;

use Atlas\Nexus\Contracts\NexusSeeder;
use Atlas\Nexus\Integrations\Prism\Tools\MemoryTool;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Services\Models\AiAssistantService;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
use Illuminate\Support\Collection;

/**
 * Class MemoryFeatureSeeder
 *
 * Seeds the built-in memory tool key by ensuring assistants list it when enabled.
 */
class MemoryFeatureSeeder implements NexusSeeder
{
    public function __construct(
        private readonly AiAssistantService $assistantService,
        private readonly ConfigRepository $config
    ) {}

    public function seed(): void
    {
        if (! $this->config->get('atlas-nexus.tools.memory.enabled', true)) {
            return;
        }

        /** @var Collection<int, AiAssistant> $assistants */
        $assistants = $this->assistantService->query()->get();

        $assistants->each(function (AiAssistant $assistant): void {
            $tools = $assistant->tools ?? [];

            if (in_array(MemoryTool::KEY, $tools, true)) {
                return;
            }

            $this->assistantService->addTool($assistant, MemoryTool::KEY);
        });
    }
}
