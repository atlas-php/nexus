<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Models;

use Atlas\Core\Services\ModelService;
use Atlas\Nexus\Models\AiAssistant;

/**
 * Class AiAssistantService
 *
 * Provides CRUD helpers for AI assistants and manages tool key assignments and prompt linkage.
 * PRD Reference: Atlas Nexus Overview — ai_assistants schema.
 *
 * @extends ModelService<AiAssistant>
 */
class AiAssistantService extends ModelService
{
    protected string $model = AiAssistant::class;

    /**
     * Sync allowed tool keys for an assistant, normalizing unique values.
     *
     * @param  array<int, string>  $tools
     */
    public function syncTools(AiAssistant $assistant, array $tools): AiAssistant
    {
        $normalized = array_values(array_unique(array_filter(array_map(
            static fn ($toolKey): string => (string) $toolKey,
            $tools
        ), static fn (string $toolKey): bool => $toolKey !== '')));

        /** @var AiAssistant $updated */
        $updated = $this->update($assistant, ['tools' => $normalized ?: null]);

        return $updated;
    }

    public function addTool(AiAssistant $assistant, string $toolKey): AiAssistant
    {
        $tools = $assistant->tools ?? [];
        $tools[] = $toolKey;

        return $this->syncTools($assistant, $tools);
    }

    public function removeTool(AiAssistant $assistant, string $toolKey): AiAssistant
    {
        $filtered = array_values(array_filter(
            array_map(static fn ($key): string => (string) $key, $assistant->tools ?? []),
            static fn (string $key): bool => $key !== $toolKey
        ));

        return $this->syncTools($assistant, $filtered);
    }
}
