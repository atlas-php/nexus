<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Seeders;

use Atlas\Nexus\Contracts\NexusSeeder;
use Atlas\Nexus\Services\Models\AiAssistantService;
use Atlas\Nexus\Services\Models\AiPromptService;
use Atlas\Nexus\Support\Threads\ThreadManagerDefaults;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Class ThreadManagerAssistantSeeder
 *
 * Seeds the built-in thread manager assistant and prompt used for title/summary generation.
 */
class ThreadManagerAssistantSeeder implements NexusSeeder
{
    public function __construct(
        private readonly AiAssistantService $assistantService,
        private readonly AiPromptService $promptService,
        private readonly ConfigRepository $config
    ) {}

    public function seed(): void
    {
        if (! $this->config->get('atlas-nexus.tools.thread_manager.enabled', true)) {
            return;
        }

        $assistant = $this->assistantService->query()
            ->where('slug', ThreadManagerDefaults::ASSISTANT_SLUG)
            ->first();

        $assistant = $assistant === null
            ? $this->assistantService->create([
                'slug' => ThreadManagerDefaults::ASSISTANT_SLUG,
                'name' => ThreadManagerDefaults::ASSISTANT_NAME,
                'description' => ThreadManagerDefaults::ASSISTANT_DESCRIPTION,
                'default_model' => $this->defaultModel(),
                'is_active' => true,
                'is_hidden' => true,
                'tools' => [],
            ])
            : $this->assistantService->update($assistant, $this->assistantUpdates($assistant));

        $prompt = $assistant->current_prompt_id
            ? $this->promptService->find($assistant->current_prompt_id)
            : null;

        if ($prompt === null) {
            $prompt = $this->promptService->create([
                'version' => ThreadManagerDefaults::PROMPT_VERSION,
                'label' => ThreadManagerDefaults::PROMPT_LABEL,
                'system_prompt' => ThreadManagerDefaults::SYSTEM_PROMPT,
                'is_active' => true,
            ]);
        } else {
            $prompt = $this->promptService->edit($prompt, [
                'label' => ThreadManagerDefaults::PROMPT_LABEL,
                'system_prompt' => ThreadManagerDefaults::SYSTEM_PROMPT,
                'is_active' => true,
            ]);
        }

        if ($assistant->current_prompt_id !== $prompt->id) {
            $this->assistantService->update($assistant, [
                'current_prompt_id' => $prompt->id,
            ]);
        }
    }

    protected function defaultModel(): string
    {
        $model = $this->config->get('atlas-nexus.tools.options.thread_manager.model')
            ?? $this->config->get('prism.default_model')
            ?? 'gpt-4o-mini';

        return is_string($model) && $model !== '' ? $model : 'gpt-4o-mini';
    }

    /**
     * @return array<string, mixed>
     */
    protected function assistantUpdates(\Atlas\Nexus\Models\AiAssistant $assistant): array
    {
        $updates = [
            'name' => ThreadManagerDefaults::ASSISTANT_NAME,
            'description' => ThreadManagerDefaults::ASSISTANT_DESCRIPTION,
            'is_active' => true,
            'is_hidden' => true,
        ];

        if (! is_string($assistant->default_model) || $assistant->default_model === '') {
            $updates['default_model'] = $this->defaultModel();
        }

        return $updates;
    }
}
