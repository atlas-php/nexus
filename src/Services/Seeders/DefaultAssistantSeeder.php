<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Seeders;

use Atlas\Nexus\Contracts\NexusSeeder;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiAssistantPrompt;
use Atlas\Nexus\Services\Models\AiAssistantPromptService;
use Atlas\Nexus\Services\Models\AiAssistantService;
use Atlas\Nexus\Support\Assistants\DefaultGeneralAssistantDefaults;
use Atlas\Nexus\Support\Assistants\DefaultHumanAssistantDefaults;

/**
 * Class DefaultAssistantSeeder
 *
 * Seeds a general-purpose assistant and prompt so consumers have a ready-to-use default.
 */
class DefaultAssistantSeeder implements NexusSeeder
{
    public function __construct(
        private readonly AiAssistantService $assistantService,
        private readonly AiAssistantPromptService $promptService
    ) {}

    public function seed(): void
    {
        $this->seedAssistant(
            DefaultGeneralAssistantDefaults::ASSISTANT_SLUG,
            DefaultGeneralAssistantDefaults::ASSISTANT_NAME,
            DefaultGeneralAssistantDefaults::ASSISTANT_DESCRIPTION,
            DefaultGeneralAssistantDefaults::SYSTEM_PROMPT
        );

        $this->seedAssistant(
            DefaultHumanAssistantDefaults::ASSISTANT_SLUG,
            DefaultHumanAssistantDefaults::ASSISTANT_NAME,
            DefaultHumanAssistantDefaults::ASSISTANT_DESCRIPTION,
            DefaultHumanAssistantDefaults::SYSTEM_PROMPT
        );
    }

    protected function defaultModel(): string
    {
        return 'gpt-5.1';
    }

    /**
     * @return array<string, mixed>
     */
    protected function assistantUpdates(AiAssistant $assistant, string $name, string $description): array
    {
        $updates = [
            'name' => $name,
            'description' => $description,
            'is_active' => true,
            'is_hidden' => false,
            'tools' => ['memory', 'thread_fetcher', 'thread_updater'],
            'provider_tools' => ['web_search', 'file_search', 'code_interpreter'],
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

    private function seedAssistant(
        string $slug,
        string $name,
        string $description,
        string $systemPrompt
    ): void {
        $assistant = $this->assistantService->query()
            ->where('slug', $slug)
            ->first();

        $assistant = $assistant === null
            ? $this->assistantService->create([
                'slug' => $slug,
                'name' => $name,
                'description' => $description,
                'default_model' => $this->defaultModel(),
                'is_active' => true,
                'is_hidden' => false,
                'tools' => ['memory', 'thread_fetcher', 'thread_updater'],
                'provider_tools' => ['web_search', 'file_search', 'code_interpreter'],
            ])
            : $this->assistantService->update($assistant, $this->assistantUpdates($assistant, $name, $description));

        $assistant->refresh()->load('currentPrompt');

        $prompt = $this->ensurePromptVersion($assistant, $systemPrompt);

        if ($assistant->current_prompt_id !== $prompt->id) {
            $this->assistantService->update($assistant, [
                'current_prompt_id' => $prompt->id,
            ]);
        }
    }
}
