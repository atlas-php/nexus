<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Seeders;

use Atlas\Nexus\Contracts\NexusSeeder;
use Atlas\Nexus\Services\Models\AiAssistantService;
use Atlas\Nexus\Services\Models\AiPromptService;
use Atlas\Nexus\Support\Assistants\DefaultAssistantDefaults;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Class DefaultAssistantSeeder
 *
 * Seeds a general-purpose assistant and prompt so consumers have a ready-to-use default.
 */
class DefaultAssistantSeeder implements NexusSeeder
{
    public function __construct(
        private readonly AiAssistantService $assistantService,
        private readonly AiPromptService $promptService,
        private readonly ConfigRepository $config
    ) {}

    public function seed(): void
    {
        $assistant = $this->assistantService->query()
            ->where('slug', DefaultAssistantDefaults::ASSISTANT_SLUG)
            ->first();

        $assistant = $assistant === null
            ? $this->assistantService->create([
                'slug' => DefaultAssistantDefaults::ASSISTANT_SLUG,
                'name' => DefaultAssistantDefaults::ASSISTANT_NAME,
                'description' => DefaultAssistantDefaults::ASSISTANT_DESCRIPTION,
                'default_model' => $this->defaultModel(),
                'is_active' => true,
                'is_hidden' => false,
                'tools' => [],
            ])
            : $this->assistantService->update($assistant, $this->assistantUpdates($assistant));

        $prompt = $this->promptService->query()
            ->where('assistant_id', $assistant->id)
            ->where('version', DefaultAssistantDefaults::PROMPT_VERSION)
            ->first();

        $prompt = $prompt === null
            ? $this->promptService->create([
                'assistant_id' => $assistant->id,
                'version' => DefaultAssistantDefaults::PROMPT_VERSION,
                'label' => DefaultAssistantDefaults::PROMPT_LABEL,
                'system_prompt' => DefaultAssistantDefaults::SYSTEM_PROMPT,
                'is_active' => true,
            ])
            : $this->promptService->update($prompt, [
                'label' => DefaultAssistantDefaults::PROMPT_LABEL,
                'system_prompt' => DefaultAssistantDefaults::SYSTEM_PROMPT,
                'is_active' => true,
            ]);

        if ($assistant->current_prompt_id !== $prompt->id) {
            $this->assistantService->update($assistant, [
                'current_prompt_id' => $prompt->id,
            ]);
        }
    }

    protected function defaultModel(): string
    {
        $model = $this->config->get('prism.default_model') ?? 'gpt-4o-mini';

        return is_string($model) && $model !== '' ? $model : 'gpt-4o-mini';
    }

    /**
     * @return array<string, mixed>
     */
    protected function assistantUpdates(\Atlas\Nexus\Models\AiAssistant $assistant): array
    {
        $updates = [
            'name' => DefaultAssistantDefaults::ASSISTANT_NAME,
            'description' => DefaultAssistantDefaults::ASSISTANT_DESCRIPTION,
            'is_active' => true,
            'is_hidden' => false,
        ];

        if (! is_string($assistant->default_model) || $assistant->default_model === '') {
            $updates['default_model'] = $this->defaultModel();
        }

        return $updates;
    }
}
