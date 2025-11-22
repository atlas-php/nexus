<?php

declare(strict_types=1);

namespace Atlas\Nexus\Services\Seeders;

use Atlas\Nexus\Contracts\NexusSeeder;
use Atlas\Nexus\Services\Models\AiAssistantService;
use Atlas\Nexus\Services\Models\AiPromptService;
use Atlas\Nexus\Support\Web\WebSummaryDefaults;
use Illuminate\Contracts\Config\Repository as ConfigRepository;

/**
 * Class WebSearchAssistantSeeder
 *
 * Seeds the built-in web summarizer assistant and prompt used by the web search tool.
 */
class WebSearchAssistantSeeder implements NexusSeeder
{
    public function __construct(
        private readonly AiAssistantService $assistantService,
        private readonly AiPromptService $promptService,
        private readonly ConfigRepository $config
    ) {}

    public function seed(): void
    {
        if (! $this->config->get('atlas-nexus.tools.web_search.enabled', true)) {
            return;
        }

        $assistant = $this->assistantService->query()
            ->where('slug', WebSummaryDefaults::ASSISTANT_SLUG)
            ->first();

        $assistant = $assistant === null
            ? $this->assistantService->create([
                'slug' => WebSummaryDefaults::ASSISTANT_SLUG,
                'name' => WebSummaryDefaults::ASSISTANT_NAME,
                'description' => WebSummaryDefaults::ASSISTANT_DESCRIPTION,
                'default_model' => $this->defaultModel(),
                'is_active' => true,
                'is_hidden' => true,
                'tools' => [],
            ])
            : $this->assistantService->update($assistant, $this->assistantUpdates($assistant));

        $prompt = $this->promptService->query()
            ->where('assistant_id', $assistant->id)
            ->where('version', WebSummaryDefaults::PROMPT_VERSION)
            ->first();

        $prompt = $prompt === null
            ? $this->promptService->create([
                'assistant_id' => $assistant->id,
                'version' => WebSummaryDefaults::PROMPT_VERSION,
                'label' => WebSummaryDefaults::PROMPT_LABEL,
                'system_prompt' => WebSummaryDefaults::SYSTEM_PROMPT,
                'is_active' => true,
            ])
            : $this->promptService->update($prompt, [
                'label' => WebSummaryDefaults::PROMPT_LABEL,
                'system_prompt' => WebSummaryDefaults::SYSTEM_PROMPT,
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
        $model = $this->config->get('atlas-nexus.tools.web_search.summary_model')
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
            'name' => WebSummaryDefaults::ASSISTANT_NAME,
            'description' => WebSummaryDefaults::ASSISTANT_DESCRIPTION,
            'is_active' => true,
            'is_hidden' => true,
        ];

        if (! is_string($assistant->default_model) || $assistant->default_model === '') {
            $updates['default_model'] = $this->defaultModel();
        }

        return $updates;
    }
}
