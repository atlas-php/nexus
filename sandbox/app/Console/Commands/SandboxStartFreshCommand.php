<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository as ConfigRepository;
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

    public function __construct(
        private readonly Filesystem $files,
        private readonly ConfigRepository $config
    ) {
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
        $this->reloadAtlasNexusConfig();
    }

    protected function reloadAtlasNexusConfig(): void
    {
        $configPath = base_path('config/atlas-nexus.php');

        if (! $this->files->exists($configPath)) {
            return;
        }

        $values = $this->files->getRequire($configPath);

        if (! is_array($values)) {
            return;
        }

        $this->config->set('atlas-nexus', $values);
        $this->components->info('Reloaded atlas-nexus configuration.');
    }

    protected function refreshPackageAssets(): void
    {
        $packageRoot = realpath(base_path('..'));

        if ($packageRoot === false) {
            $this->components->warn('Unable to locate package root; skipping asset refresh.');

            return;
        }

        $this->copyFile(
            $packageRoot.'/config/atlas-nexus.php',
            base_path('config/atlas-nexus.php'),
            'config/atlas-nexus.php'
        );

        foreach ($this->aiMigrationFiles as $file) {
            $source = $packageRoot.'/database/migrations/'.$file;
            $target = base_path('database/migrations/'.$file);

            $this->copyFile($source, $target, "AI migration [{$file}]");
        }
    }

    protected function copyFile(string $source, string $target, string $label): void
    {
        if (! $this->files->exists($source)) {
            $this->components->warn("Skipping {$label}: source missing.");

            return;
        }

        if ($this->files->exists($target)) {
            $this->files->delete($target);
        }

        $directory = dirname($target);

        if (! $this->files->isDirectory($directory)) {
            $this->files->makeDirectory($directory, 0755, true);
        }

        $this->files->copy($source, $target);
        $this->components->info("Synced {$label} from package root.");
    }
}
