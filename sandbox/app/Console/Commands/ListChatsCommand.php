<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ChatTranscriptService;
use Illuminate\Console\Command;

class ListChatsCommand extends Command
{
    protected $signature = 'sandbox:list-chats';

    protected $description = 'Display available chat transcript files saved by the sandbox tools.';

    public function __construct(
        private readonly ChatTranscriptService $chatTranscriptService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $files = $this->chatTranscriptService->listTranscripts();

        if ($files === []) {
            $this->components->info('No chat transcripts have been saved yet.');

            return self::SUCCESS;
        }

        $this->table(['#', 'Filename'], collect($files)->map(fn ($file, $index) => [$index + 1, $file])->all());

        return self::SUCCESS;
    }
}
