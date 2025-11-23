<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\User;
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
        $this->line('Configure assistants via sandbox/config/atlas-nexus.php before running nexus:chat.');

        return self::SUCCESS;
    }
}
