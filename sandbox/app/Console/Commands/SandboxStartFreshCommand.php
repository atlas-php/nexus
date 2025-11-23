<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

/**
 * Class SandboxStartFreshCommand
 *
 * Rebuilds the sandbox database, runs default seeders, and provisions Nexus fixtures in one step.
 */
class SandboxStartFreshCommand extends Command
{
    /**
     * @var array<int, string>
     */
    protected array $aiMigrationFiles = [
        '2025_01_01_000000_create_ai_assistants_table.php',
        '2025_01_01_000100_create_ai_assistant_prompts_table.php',
        '2025_01_01_000200_create_ai_threads_table.php',
        '2025_01_01_000300_create_ai_messages_table.php',
        '2025_01_01_000600_create_ai_tool_runs_table.php',
        '2025_01_01_000700_create_ai_memories_table.php',
    ];

    protected $signature = 'sandbox:start-fresh
        {--skip-seed : Skip running the default database seeders}
        {--skip-setup : Skip running nexus:setup after database refresh}';

    protected $description = 'Clean AI migration files, migrate fresh, seed, and run Nexus setup in one step.';

    public function __construct(private readonly Filesystem $files)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->refreshPackageAssets();

        $this->components->info('Refreshing sandbox database via migrate:fresh --force...');
        $this->call('migrate:fresh', ['--force' => true]);

        if ($this->option('skip-seed')) {
            $this->components->warn('Skipping database seeds.');
        } else {
            $this->components->info('Seeding sandbox data...');
            $this->call('db:seed', ['--force' => true]);
        }

        $this->components->info('Seeding Nexus default assistants/prompts via atlas:nexus:seed...');
        $this->call('atlas:nexus:seed');

        if ($this->option('skip-setup')) {
            $this->components->warn('Skipping Nexus setup.');
        } else {
            $this->components->info('Running Nexus sandbox setup...');
            $this->call('nexus:setup');
        }

        $this->components->info('Sandbox environment refreshed and ready.');

        return self::SUCCESS;
    }

    protected function refreshPackageAssets(): void
    {
        foreach ($this->aiMigrationFiles as $file) {
            $path = base_path('database/migrations/'.$file);

            if (! $this->files->exists($path)) {
                $this->components->warn("AI migration [{$file}] not found in sandbox, skipping delete.");

                continue;
            }

            $this->files->delete($path);
            $this->components->info("Deleted AI migration [{$file}] from sandbox.");
        }
    }

    // No additional helpers required.
}
