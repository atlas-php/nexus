<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

/**
 * Class SandboxStartFreshCommand
 *
 * Rebuilds the sandbox database, runs default seeders, and provisions Nexus fixtures in one step.
 */
class SandboxStartFreshCommand extends Command
{
    protected $signature = 'sandbox:start-fresh
        {--skip-seed : Skip running the default database seeders}
        {--skip-setup : Skip running nexus:setup after database refresh}';

    protected $description = 'Perform migrate:fresh --force, db:seed, and nexus:setup as a single sandbox reset command.';

    public function handle(): int
    {
        $this->components->info('Refreshing sandbox database via migrate:fresh --force...');
        $this->call('migrate:fresh', ['--force' => true]);

        if ($this->option('skip-seed')) {
            $this->components->warn('Skipping database seeds.');
        } else {
            $this->components->info('Seeding sandbox data...');
            $this->call('db:seed', ['--force' => true]);
        }

        if ($this->option('skip-setup')) {
            $this->components->warn('Skipping Nexus setup.');
        } else {
            $this->components->info('Running Nexus sandbox setup...');
            $this->call('nexus:setup');
        }

        $this->components->info('Sandbox environment refreshed and ready.');

        return self::SUCCESS;
    }
}
