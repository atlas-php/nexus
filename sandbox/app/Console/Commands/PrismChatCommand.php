<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\ChatTranscriptService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Prism\Prism\Contracts\Message as PrismMessage;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Prism\Prism\Streaming\Events\ToolCallEvent;
use Prism\Prism\Streaming\Events\ToolResultEvent;
use Prism\Prism\Text\PendingRequest;
use Prism\Prism\Text\Response;
use Prism\Prism\Tool;
use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\ToolResultMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;
use Prism\Prism\ValueObjects\ToolResult;
use Throwable;

/**
 * Maintains a conversational Prism session so prompts can be exchanged interactively within the CLI.
 */
class PrismChatCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'prism:chat
        {--provider= : Override the Prism provider key (defaults to env PRISM_DEFAULT_PROVIDER)}
        {--model= : Override the model name (defaults to env PRISM_DEFAULT_MODEL)}
        {--system= : Optional system prompt to guide the assistant}
        {--stream : Stream tokens as they arrive}';

    /**
     * @var string
     */
    protected $description = 'Keep an interactive chat session with Prism running until you exit.';

    /**
     * @var array<int, PrismMessage>
     */
    protected array $conversation = [];

    /**
     * @var array<int, string>
     */
    protected array $transcriptEntries = [];

    protected ?string $lastToolResponse = null;

    public function __construct(
        private readonly ChatTranscriptService $chatTranscriptService
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $provider = (string) ($this->option('provider') ?? env('PRISM_DEFAULT_PROVIDER', 'openai'));
        $model = (string) ($this->option('model') ?? env('PRISM_DEFAULT_MODEL', 'gpt-4o-mini'));
        $system = (string) ($this->option('system') ?? env('PRISM_SYSTEM_PROMPT', ''));

        $this->components->info('Starting Prism chat session. Type "exit" to quit.');

        while (true) {
            $input = trim((string) $this->ask('You'));

            if ($input === '') {
                continue;
            }

            if (in_array(strtolower($input), ['exit', 'quit'], true)) {
                $this->components->info('Ending chat session.');

                return self::SUCCESS;
            }

            $this->conversation[] = new UserMessage($input);
            $this->recordTranscriptEntry('user', $input);
            $this->lastToolResponse = null;

            $pending = Prism::text()
                ->using($provider, $model)
                ->withMessages($this->conversation)
                ->withTools([
                    $this->chatLogTool(),
                    $this->chatListTool(),
                    $this->chatViewTool(),
                ])
                ->withMaxSteps($this->maxSteps())
                ->onComplete(function (?PendingRequest $request, Collection $messages): void {
                    $this->conversation = $messages->values()->all();
                });

            if ($system !== '') {
                $pending->withSystemPrompt($system);
            }

            $isStreaming = (bool) $this->option('stream');

            try {
                $assistantReply = $this->option('stream')
                    ? $this->streamResponse($pending->asStream())
                    : $this->resolveTextResponse($pending->asText());
            } catch (Throwable $exception) {
                array_pop($this->conversation); // remove the user turn since it failed
                $this->components->error($exception->getMessage());

                continue;
            }

            if ($assistantReply === null) {
                if ($this->handleToolOnlyReply()) {
                    continue;
                }

                $this->components->error('Prism did not return a response.');
                array_pop($this->conversation);

                continue;
            }

            if ($isStreaming) {
                $this->lastToolResponse = null;
            }
        }
    }

    protected function resolveTextResponse(?Response $response): ?string
    {
        if ($response === null) {
            return null;
        }

        $this->displayAgentResponse($response->text);
        $this->conversation = $response->messages->values()->all();

        return $response->text;
    }

    /**
     * @param  iterable<int, StreamEvent>  $chunks
     */
    protected function streamResponse(iterable $chunks): ?string
    {
        $buffer = '';
        $activeTools = [];
        $this->line('');
        $this->output->writeln(' Agent:');

        foreach ($chunks as $chunk) {
            if ($chunk instanceof ToolCallEvent) {
                $activeTools[$chunk->toolCall->id] = $chunk->toolCall->name;
                $this->output->writeln(sprintf(' (working: %s)', $chunk->toolCall->name));

                continue;
            }

            if ($chunk instanceof ToolResultEvent) {
                $toolName = $activeTools[$chunk->toolResult->toolCallId] ?? $chunk->toolResult->toolName;
                unset($activeTools[$chunk->toolResult->toolCallId]);
                $this->output->writeln(sprintf(' (completed: %s)', $toolName));

                continue;
            }

            if ($chunk instanceof TextDeltaEvent) {
                $this->output->write($chunk->delta);
                $buffer .= $chunk->delta;
            }

            if ($chunk instanceof ErrorEvent) {
                $this->components->error($chunk->message);

                return null;
            }
        }

        $this->newLine(2);

        if ($buffer !== '') {
            $this->recordTranscriptEntry('agent', $buffer);
            $this->lastToolResponse = null;

            return $buffer;
        }

        $content = $this->latestAssistantContent();

        if ($content !== null) {
            $this->output->writeln(' '.$content);
            $this->newLine();
            $this->recordTranscriptEntry('agent', $content);
            $this->lastToolResponse = null;
        }

        return $content;
    }

    protected function chatLogTool(): Tool
    {
        $service = $this->chatTranscriptService;

        return (new Tool)
            ->as('store_chat_log')
            ->for('Persist the full chat transcript to disk for later review.')
            ->withStringParameter('notes', 'Optional summary describing why the transcript was saved.', false)
            ->using(function (?string $notes = null) use ($service): string {
                $payload = $this->formatConversationTranscript($notes);
                $path = $service->persist($payload);

                $message = sprintf('Full chat transcript saved to %s', $path);
                $this->lastToolResponse = $message;

                return $message;
            });
    }

    protected function chatListTool(): Tool
    {
        $service = $this->chatTranscriptService;

        return (new Tool)
            ->as('list_saved_chats')
            ->for('Return the available saved chat transcript filenames for reference.')
            ->using(function () use ($service): string {
                $files = $service->listTranscripts();

                if ($files === []) {
                    return 'No chat transcripts are currently stored.';
                }

                return "Saved chats:\n".implode("\n", $files);
            });
    }

    protected function chatViewTool(): Tool
    {
        $service = $this->chatTranscriptService;

        return (new Tool)
            ->as('view_saved_chat')
            ->for('Read the contents of a specific saved chat transcript.')
            ->withStringParameter('filename', 'The filename returned from list_saved_chats.')
            ->using(function (string $filename) use ($service): string {
                try {
                    return $service->readTranscript($filename);
                } catch (\Throwable $exception) {
                    return 'Unable to open chat: '.$exception->getMessage();
                }
            });
    }

    protected function latestAssistantContent(): ?string
    {
        $assistant = collect($this->conversation)
            ->reverse()
            ->first(fn (PrismMessage $message): bool => $message instanceof AssistantMessage && $message->content !== '');

        if ($assistant instanceof AssistantMessage) {
            return $assistant->content;
        }

        $toolResultMessage = collect($this->conversation)
            ->reverse()
            ->first(fn (PrismMessage $message): bool => $message instanceof ToolResultMessage);

        if ($toolResultMessage instanceof ToolResultMessage) {
            return $this->renderToolResults($toolResultMessage->toolResults);
        }

        return null;
    }

    protected function handleToolOnlyReply(): bool
    {
        if ($this->lastToolResponse !== null) {
            $content = $this->lastToolResponse;
            $this->lastToolResponse = null;
            $this->appendAssistantMessage($content);

            return true;
        }

        $toolResultMessage = collect($this->conversation)
            ->reverse()
            ->first(fn (PrismMessage $message): bool => $message instanceof ToolResultMessage);

        if (! $toolResultMessage instanceof ToolResultMessage) {
            return false;
        }

        $content = $this->renderToolResults($toolResultMessage->toolResults);

        if ($content === '') {
            return false;
        }

        $this->appendAssistantMessage($content);

        return true;
    }

    protected function appendAssistantMessage(string $content): void
    {
        $assistantMessage = new AssistantMessage($content);
        $this->conversation[] = $assistantMessage;

        $this->displayAgentResponse($content);
    }

    protected function formatConversationTranscript(?string $notes): string
    {
        $entries = $this->transcriptEntries;

        if ($notes !== null && $notes !== '') {
            array_unshift($entries, "Notes:\n{$notes}");
        }

        return implode(PHP_EOL.PHP_EOL, $entries);
    }

    /**
     * @param  ToolResult[]  $results
     */
    protected function renderToolResults(array $results): string
    {
        return collect($results)
            ->map(fn (ToolResult $result): string => sprintf(
                '[tool:%s] args=%s result=%s',
                $result->toolName,
                json_encode($result->args, JSON_PRETTY_PRINT) ?: '[]',
                json_encode($result->result, JSON_PRETTY_PRINT) ?: 'null'
            ))->implode(PHP_EOL);
    }

    protected function recordTranscriptEntry(string $role, string $content): void
    {
        if ($content === '') {
            return;
        }

        $this->transcriptEntries[] = sprintf('[%s]%s%s', $role, PHP_EOL, $content);
    }

    protected function displayAgentResponse(string $content): void
    {
        if ($content === '') {
            return;
        }

        $this->line('');
        $this->output->writeln(' Agent:');

        foreach (preg_split('/\r?\n/', $content) ?: [] as $line) {
            $this->output->writeln(' '.$line);
        }

        $this->line('');
        $this->recordTranscriptEntry('agent', $content);
        $this->lastToolResponse = null;
    }

    protected function maxSteps(): int
    {
        return (int) env('PRISM_MAX_STEPS', 8);
    }
}
