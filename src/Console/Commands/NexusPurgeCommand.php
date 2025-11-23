<?php

declare(strict_types=1);

namespace Atlas\Nexus\Console\Commands;

use Atlas\Nexus\Services\NexusPurgeService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Class NexusPurgeCommand
 *
 * Gives consumers a CLI entry point to permanently delete trashed Nexus data.
 */
class NexusPurgeCommand extends Command
{
    protected $signature = 'atlas:nexus:purge
        {--chunk=100 : Number of trashed rows processed per chunk}';

    protected $description = 'Permanently delete soft-deleted Nexus records.';

    public function handle(NexusPurgeService $purgeService): int
    {
        $chunk = $this->option('chunk');

        $chunkSize = is_numeric($chunk)
            ? max(1, (int) $chunk)
            : NexusPurgeService::DEFAULT_CHUNK_SIZE;

        if (! is_numeric($chunk) || (int) $chunk <= 0) {
            $this->components->warn(sprintf(
                'Invalid chunk size provided, defaulting to %d.',
                NexusPurgeService::DEFAULT_CHUNK_SIZE
            ));
        }

        $results = $purgeService->purge($chunkSize);
        $total = array_sum($results);

        $this->components->info(sprintf('Purged %d soft-deleted Nexus records.', $total));

        foreach ($results as $label => $count) {
            $this->components->twoColumnDetail(Str::headline($label), (string) $count);
        }

        return self::SUCCESS;
    }
}
