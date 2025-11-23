<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Atlas\Nexus\Enums\AiThreadStatus;
use Atlas\Nexus\Enums\AiThreadType;
use Atlas\Nexus\Models\AiAssistantPrompt;
use Atlas\Nexus\Services\Models\AiAssistantPromptService;
use Atlas\Nexus\Services\Models\AiAssistantService;
use Atlas\Nexus\Support\Assistants\DefaultGeneralAssistantDefaults;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Seeds a sandbox-ready Nexus assistant, prompt, user, and thread for quick CLI testing.
 */
class NexusSetupCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nexus:setup
        {--name=John : Name for the fake user}
        {--email=john@company.com : Email for the fake user}';

    /**
     * @var string
     */
    protected $description = 'Create a fake user, assistant, prompt, and thread suitable for nexus:chat sandbox sessions.';

    public function __construct(
        private readonly AiAssistantService $assistantService,
        private readonly AiAssistantPromptService $promptService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $email = (string) $this->option('email');
        $name = (string) $this->option('name');
        $assistantSlug = DefaultGeneralAssistantDefaults::ASSISTANT_SLUG;
        $systemPrompt = DefaultGeneralAssistantDefaults::SYSTEM_PROMPT;

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password'),
            ]
        );

        $assistant = $this->assistantService->query()
            ->where('slug', $assistantSlug)
            ->first();

        if ($assistant === null) {
            $this->components->error('Default Nexus assistant not found. Run atlas:nexus:seed first.');

            return self::FAILURE;
        }

        $assistant->refresh()->load('currentPrompt');
        $prompt = $assistant->currentPrompt;

        if ($prompt === null) {
            $prompt = $this->promptService->create([
                'assistant_id' => $assistant->id,
                'system_prompt' => $systemPrompt,
                'is_active' => true,
            ]);
        } elseif ($this->promptNeedsUpdate($prompt, $systemPrompt)) {
            $prompt = $this->promptService->edit($prompt, [
                'system_prompt' => $systemPrompt,
                'is_active' => true,
            ]);
        }

        if ($assistant->current_prompt_id !== $prompt->id) {
            $this->assistantService->update($assistant, [
                'current_prompt_id' => $prompt->id,
            ]);
            $assistant->refresh();
        }

        $this->components->info('Sandbox Nexus setup complete.');
        $this->line(sprintf('User: %s (%s)', $user->name, $user->email));
        $this->line(sprintf('Assistant: %s (slug: %s)', $assistant->name, $assistant->slug));
        $this->line(sprintf('Prompt version: %s', $prompt->version));
        $this->line('Create a thread manually or via the UI before running nexus:chat.');

        return self::SUCCESS;
    }

    protected function promptNeedsUpdate(?AiAssistantPrompt $prompt, string $systemPrompt): bool
    {
        if ($prompt === null) {
            return true;
        }

        return trim((string) $prompt->system_prompt) !== trim($systemPrompt) || ! $prompt->is_active;
    }
}
