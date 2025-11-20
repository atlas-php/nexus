<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ChatTranscriptService;
use Illuminate\Console\Command;

class ViewChatCommand extends Command
{
    protected $signature = 'sandbox:view-chat {filename : The chat transcript filename to display}';

    protected $description = 'Output the contents of a stored chat transcript.';

    public function __construct(
        private readonly ChatTranscriptService $chatTranscriptService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $filename = (string) $this->argument('filename');

        if ($filename === '') {
            $this->components->error('A filename is required.');

            return self::FAILURE;
        }

        try {
            $contents = $this->chatTranscriptService->readTranscript($filename);
        } catch (\Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line('');
        $this->components->info(sprintf('Chat transcript: %s', $filename));
        $this->line('');
        $this->output->writeln($contents);
        $this->line('');

        return self::SUCCESS;
    }
}
