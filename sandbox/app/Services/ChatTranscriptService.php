<?php

declare(strict_types=1);

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Filesystem\Filesystem;
use RuntimeException;

/**
 * Persists inbound chat transcripts to timestamped files within storage/private/chats.
 */
class ChatTranscriptService
{
    public function __construct(
        private readonly Filesystem $filesystem
    ) {}

    /**
     * Store the provided payload as a timestamped chat file.
     */
    public function persist(string $payload): string
    {
        $directory = storage_path('app/private/chats');
        $this->filesystem->ensureDirectoryExists($directory, 0755, true);

        $timestamp = CarbonImmutable::now()->format('Ymd_His_u');
        $path = $directory.'/'.$timestamp.'.txt';

        $this->filesystem->put($path, $payload);

        return $path;
    }

    /**
     * @return array<int, string>
     */
    public function listTranscripts(): array
    {
        $directory = storage_path('app/private/chats');

        if (! $this->filesystem->exists($directory)) {
            return [];
        }

        return collect($this->filesystem->files($directory))
            ->map(fn (string $file): string => basename($file))
            ->sortDesc()
            ->values()
            ->all();
    }

    public function readTranscript(string $filename): string
    {
        $path = str_starts_with($filename, storage_path())
            ? $filename
            : storage_path('app/private/chats/'.$filename);

        if (! $this->filesystem->exists($path)) {
            throw new RuntimeException(sprintf('Transcript [%s] does not exist.', $filename));
        }

        return $this->filesystem->get($path);
    }
}
