<?php

declare(strict_types=1);

namespace Atlas\Nexus\Console\Commands;

use Atlas\Nexus\Services\Seeders\NexusSeederService;
use Illuminate\Console\Command;

/**
 * Class NexusSeedCommand
 *
 * Exposes seeding for built-in Nexus resources so consumers can initialize defaults post-migration.
 */
class NexusSeedCommand extends Command
{
    protected $signature = 'atlas:nexus:seed {--seeder=* : Limit execution to specific seeder class names}';

    protected $description = 'Seed built-in Atlas Nexus resources.';

    public function handle(NexusSeederService $seederService): int
    {
        $seeders = $this->option('seeder');
        $seeders = is_array($seeders) && $seeders !== [] ? $seeders : null;

        $seederService->run($seeders);
        $this->components->info('Atlas Nexus seeding completed.');

        return self::SUCCESS;
    }
}
