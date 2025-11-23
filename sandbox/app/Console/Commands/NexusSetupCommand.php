<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
use Atlas\Nexus\Services\Assistants\AssistantRegistry;
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
    protected $description = 'Create a fake user suitable for Nexus sandbox sessions.';

    public function __construct(private readonly AssistantRegistry $assistantRegistry)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $email = (string) $this->option('email');
        $name = (string) $this->option('name');

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make('password'),
            ]
        );

        $this->components->info('Sandbox Nexus setup complete.');
        $this->line(sprintf('User: %s (%s)', $user->name, $user->email));

        $assistantKeys = $this->assistantRegistry->keys();
        $assistantKey = $assistantKeys[0] ?? null;

        if ($assistantKey === null) {
            $this->components->warn('No assistants registered. Configure sandbox/config/atlas-nexus.php before running nexus:chat.');

            return self::SUCCESS;
        }

        $this->line('');
        $this->components->info('Next, start a chat session with:');
        $this->line(sprintf(
            'php artisan nexus:chat --assistant=%s --user=%d',
            $assistantKey,
            $user->id
        ));

        if (count($assistantKeys) > 1) {
            $this->line('');
            $this->components->info('Other available assistants:');
            $this->line(implode(', ', $assistantKeys));
        }

        return self::SUCCESS;
    }
}
