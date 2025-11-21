<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Atlas\Nexus\Enums\AiThreadStatus;
use Atlas\Nexus\Enums\AiThreadType;
use Atlas\Nexus\Services\Models\AiAssistantService;
use Atlas\Nexus\Services\Models\AiPromptService;
use Atlas\Nexus\Services\Models\AiThreadService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Seeds a sandbox-ready Nexus assistant, prompt, user, and thread for quick CLI testing.
 */
class NexusSetupCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nexus:setup
        {--assistant=sandbox-assistant : Assistant slug to create or reuse}
        {--name=Sandbox Tester : Name for the fake user}
        {--email=sandbox@example.com : Email for the fake user}
        {--prompt=You are a helpful assistant. : System prompt content}
        {--model=gpt-4.1 : Default model for the assistant}';

    /**
     * @var string
     */
    protected $description = 'Create a fake user, assistant, prompt, and thread suitable for nexus:chat sandbox sessions.';

    public function __construct(
        private readonly AiAssistantService $assistantService,
        private readonly AiPromptService $promptService,
        private readonly AiThreadService $threadService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $email = (string) $this->option('email');
        $name = (string) $this->option('name');
        $assistantSlug = Str::slug((string) $this->option('assistant'));
        $systemPrompt = (string) $this->option('prompt');
        $model = (string) $this->option('model');

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
            $assistant = $this->assistantService->create([
                'slug' => $assistantSlug,
                'name' => Str::title(str_replace('-', ' ', $assistantSlug)),
                'description' => 'Sandbox assistant for local Nexus chat tests.',
                'default_model' => $model,
                'tools' => ['memory', 'web_search'],
                'is_active' => true,
            ]);
        }

        $prompt = $this->promptService->query()
            ->where('assistant_id', $assistant->id)
            ->where('version', 1)
            ->first();

        if ($prompt === null) {
            $prompt = $this->promptService->create([
                'assistant_id' => $assistant->id,
                'version' => 1,
                'label' => 'Sandbox Prompt',
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

        $thread = $this->threadService->query()
            ->where('assistant_id', $assistant->id)
            ->where('user_id', $user->id)
            ->first();

        if ($thread === null) {
            $thread = $this->threadService->create([
                'assistant_id' => $assistant->id,
                'user_id' => $user->id,
                'type' => AiThreadType::USER->value,
                'status' => AiThreadStatus::OPEN->value,
                'prompt_id' => $prompt->id,
                'title' => 'Sandbox Chat',
                'summary' => null,
                'metadata' => [],
            ]);
        }

        $this->components->info('Sandbox Nexus setup complete.');
        $this->line(sprintf('User: %s (%s)', $user->name, $user->email));
        $this->line(sprintf('Assistant: %s (slug: %s)', $assistant->name, $assistant->slug));
        $this->line(sprintf('Prompt version: %s', $prompt->version));
        $this->line(sprintf('Thread ID: %s', $thread->id));
        $this->line('Use: php artisan nexus:chat --assistant='.$assistant->slug.' --thread='.$thread->id.' --user='.$user->id);

        return self::SUCCESS;
    }
}
