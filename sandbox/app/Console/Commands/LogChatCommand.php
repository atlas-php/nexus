<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ChatTranscriptService;
use Illuminate\Console\Command;

/**
 * Writes arbitrary chat payloads to timestamped files inside storage/private/chats for local inspection.
 */
class LogChatCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'sandbox:log-chat
        {payload : The text or JSON blob to persist into the chat archive}';

    /**
     * @var string
     */
    protected $description = 'Persist manual chat payloads to storage/private/chats for testing.';

    public function __construct(
        private readonly ChatTranscriptService $chatTranscriptService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $payload = (string) $this->argument('payload');

        if ($payload === '') {
            $this->components->error('The payload cannot be empty.');

            return self::FAILURE;
        }

        $path = $this->chatTranscriptService->persist($payload);

        $this->components->info(sprintf('Chat payload stored at: %s', $path));

        return self::SUCCESS;
    }
}
