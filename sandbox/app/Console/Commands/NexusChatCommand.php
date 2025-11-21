<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Atlas\Nexus\Enums\AiMessageRole;
use Atlas\Nexus\Enums\AiMessageStatus;
use Atlas\Nexus\Enums\AiThreadStatus;
use Atlas\Nexus\Enums\AiThreadType;
use Atlas\Nexus\Models\AiAssistant;
use Atlas\Nexus\Models\AiMessage;
use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Models\AiAssistantService;
use Atlas\Nexus\Services\Models\AiMessageService;
use Atlas\Nexus\Services\Models\AiThreadService;
use Atlas\Nexus\Services\Threads\ThreadMessageService;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Provides an interactive CLI chat loop that uses Nexus thread/message services and queued assistant responses.
 */
class NexusChatCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nexus:chat
        {--assistant= : Assistant slug to chat with (required)}
        {--thread= : Existing thread ID to reuse}
        {--user= : User ID to attribute user messages (defaults to 1)}
        {--title= : Optional title when creating a new thread}';

    /**
     * @var string
     */
    protected $description = 'Keep a Nexus chat session alive, dispatching assistant responses via jobs and polling status.';

    public function __construct(
        private readonly AiAssistantService $assistantService,
        private readonly AiThreadService $threadService,
        private readonly AiMessageService $messageService,
        private readonly ThreadMessageService $threadMessageService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $assistantSlug = (string) ($this->option('assistant') ?? $this->ask('Assistant slug'));
        $userId = (int) ($this->option('user') ?? 1);

        if ($assistantSlug === '') {
            $this->components->error('An assistant slug is required.');

            return self::FAILURE;
        }

        /** @var AiAssistant|null $assistant */
        $assistant = $this->assistantService->query()
            ->where('slug', $assistantSlug)
            ->first();

        if ($assistant === null) {
            $this->components->error("Assistant [{$assistantSlug}] was not found.");

            return self::FAILURE;
        }

        $thread = $this->resolveThread($assistant, $userId);

        $this->components->info(sprintf(
            'Nexus chat ready for assistant [%s] on thread #%s. Type "exit" to quit.',
            $assistant->slug,
            $thread->id
        ));

        $this->line('');
        $this->displayExistingMessages($thread);

        while (true) {
            $input = trim((string) $this->ask('You'));

            if ($input === '') {
                continue;
            }

            if (in_array(strtolower($input), ['exit', 'quit'], true)) {
                $this->components->info('Ending Nexus chat session.');

                return self::SUCCESS;
            }

            try {
                $messages = $this->threadMessageService->sendUserMessage($thread, $input, $userId);
            } catch (\Throwable $exception) {
                $this->components->error('Could not send message, try again after the assistant finishes.');

                continue;
            }

            $resolved = $this->waitForAssistant($messages['assistant']);

            if ($resolved === null) {
                $this->components->warn('Assistant response is still processing. You can continue chatting or wait a moment.');

                continue;
            }

            $this->displayMessage($resolved);
        }
    }

    protected function resolveThread(AiAssistant $assistant, int $userId): AiThread
    {
        $threadId = $this->option('thread');

        if ($threadId !== null && $threadId !== '') {
            try {
                /** @var AiThread $thread */
                $thread = $this->threadService->findOrFail((int) $threadId);

                if ($thread->assistant_id === $assistant->id) {
                    return $thread;
                }

                $this->components->warn('Thread does not belong to this assistant. A new thread will be created.');
            } catch (ModelNotFoundException $exception) {
                $this->components->warn(sprintf('Thread [%s] was not found. A new thread will be created.', $threadId));
            }
        }

        /** @var AiThread $thread */
        $thread = $this->threadService->create([
            'assistant_id' => $assistant->id,
            'user_id' => $userId,
            'type' => AiThreadType::USER->value,
            'status' => AiThreadStatus::OPEN->value,
            'prompt_id' => $assistant->current_prompt_id,
            'title' => $this->option('title') !== null ? (string) $this->option('title') : null,
            'summary' => null,
            'metadata' => [],
        ]);

        $this->components->info(sprintf('Created new thread #%s for assistant [%s].', $thread->id, $assistant->slug));

        return $thread;
    }

    protected function displayExistingMessages(AiThread $thread): void
    {
        $messages = $this->messageService->query()
            ->where('thread_id', $thread->id)
            ->whereIn('status', [
                AiMessageStatus::COMPLETED->value,
                AiMessageStatus::FAILED->value,
            ])
            ->orderBy('sequence')
            ->get();

        if ($messages->isEmpty()) {
            $this->components->info('No prior messages found for this thread.');

            return;
        }

        foreach ($messages as $message) {
            $this->displayMessage($message);
        }
    }

    protected function displayMessage(AiMessage $message): void
    {
        $label = $message->role === AiMessageRole::USER ? 'You' : 'Assistant';

        if ($message->status === AiMessageStatus::FAILED) {
            $reason = $message->failed_reason ?? 'Unknown failure.';
            $this->components->error(sprintf('%s (failed):', $label));
            $this->components->error(' '.$reason);
            $this->line('');

            return;
        }

        if ($message->status !== AiMessageStatus::COMPLETED) {
            return;
        }

        $this->line(sprintf('%s:', $label));
        $this->line(' '.$message->content);
        $this->line('');
    }

    protected function waitForAssistant(AiMessage $assistantMessage): ?AiMessage
    {
        $timeoutSeconds = 120;
        $deadline = now()->addSeconds($timeoutSeconds);

        do {
            $assistantMessage->refresh();

            if ($assistantMessage->status !== AiMessageStatus::PROCESSING) {
                return $assistantMessage;
            }

            usleep(500_000);
        } while (now()->lessThan($deadline));

        return null;
    }
}
