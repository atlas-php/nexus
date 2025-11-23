<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Seeders;

use Atlas\Nexus\Contracts\NexusSeeder;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiAssistantPrompt;
use Atlas\Nexus\Services\Models\AiAssistantPromptService;
use Atlas\Nexus\Services\Models\AiAssistantService;
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
        private readonly AiAssistantPromptService $promptService,
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

        $assistant->refresh()->load('currentPrompt');

        $prompt = $this->ensurePromptVersion($assistant, DefaultAssistantDefaults::SYSTEM_PROMPT);

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
    protected function assistantUpdates(AiAssistant $assistant): array
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

    protected function ensurePromptVersion(AiAssistant $assistant, string $systemPrompt): AiAssistantPrompt
    {
        $prompt = $assistant->currentPrompt;

        if ($prompt === null) {
            return $this->promptService->create([
                'assistant_id' => $assistant->id,
                'system_prompt' => $systemPrompt,
                'is_active' => true,
            ]);
        }

        if ($this->promptRequiresUpdate($prompt, $systemPrompt)) {
            return $this->promptService->edit($prompt, [
                'system_prompt' => $systemPrompt,
                'is_active' => true,
            ]);
        }

        return $prompt;
    }

    protected function promptRequiresUpdate(AiAssistantPrompt $prompt, string $systemPrompt): bool
    {
        return trim($prompt->system_prompt) !== trim($systemPrompt) || ! $prompt->is_active;
    }
}
