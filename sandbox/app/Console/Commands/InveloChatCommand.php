<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class InveloChatCommand extends Command
{
    protected $signature = 'invelo:chat {question? : The question to send to Invelo vector search}';

    protected $description = 'Chat with the Invelo vector knowledge base using OpenAI\'s Responses API.';

    /**
     * @var array<int, array<string,mixed>>
     */
    protected array $conversation = [];

    public function handle(): int
    {
        $apiKey = env('OPENAI_API_KEY');
        $promptId = env('INVELO_PROMPT_ID');
        $promptVersion = env('INVELO_PROMPT_VERSION', '3');
        $vectorStoreId = env('INVELO_VECTOR_STORE_ID');

        if ($apiKey === null || $apiKey === '') {
            $this->components->error('OPENAI_API_KEY is not configured.');

            return self::FAILURE;
        }

        foreach (['INVELO_PROMPT_ID' => $promptId, 'INVELO_VECTOR_STORE_ID' => $vectorStoreId] as $label => $value) {
            if ($value === null || $value === '') {
                $this->components->error(sprintf('%s must be set in your .env file.', $label));

                return self::FAILURE;
            }
        }

        $initialQuestion = (string) ($this->argument('question') ?? '');

        if ($initialQuestion !== '') {
            if (! $this->sendQuestion($initialQuestion, $apiKey, $promptId, $promptVersion, $vectorStoreId)) {
                return self::FAILURE;
            }
        }

        $this->components->info('Starting Invelo chat. Type "exit" to quit.');

        while (true) {
            $question = $this->promptUser();

            if ($question === '') {
                continue;
            }

            if (in_array(strtolower($question), ['exit', 'quit'], true)) {
                $this->components->info('Ending Invelo chat session.');

                return self::SUCCESS;
            }

            if (! $this->sendQuestion($question, $apiKey, $promptId, $promptVersion, $vectorStoreId)) {
                return self::FAILURE;
            }
        }
    }

    protected function promptUser(): string
    {
        $this->line('');
        $this->output->writeln(' You:');
        $this->output->write(' > ');

        $input = fgets(STDIN);
        $this->line('');

        return trim($input !== false ? $input : '');
    }

    protected function sendQuestion(string $question, string $apiKey, string $promptId, string $promptVersion, string $vectorStoreId): bool
    {
        $this->conversation[] = [
            'role' => 'user',
            'content' => [
                ['type' => 'input_text', 'text' => $question],
            ],
        ];

        try {
            $response = Http::withToken($apiKey)
                ->acceptJson()
                ->post('https://api.openai.com/v1/responses', [
                    'prompt' => [
                        'id' => $promptId,
                        'version' => $promptVersion,
                    ],
                    'input' => $this->conversation,
                    'text' => [
                        'format' => [
                            'type' => 'text',
                        ],
                    ],
                    'reasoning' => (object) [],
                    'tools' => [
                        [
                            'type' => 'file_search',
                            'vector_store_ids' => [$vectorStoreId],
                        ],
                    ],
                    'max_output_tokens' => (int) env('INVELO_MAX_OUTPUT_TOKENS', 2048),
                    'store' => true,
                    'include' => ['web_search_call.action.sources'],
                ]);
        } catch (Throwable $exception) {
            $this->components->error('Invelo chat failed: '.$exception->getMessage());
            array_pop($this->conversation);

            return false;
        }

        if ($response->failed()) {
            $body = $response->json();
            $message = $body['error']['message'] ?? $response->body();
            $this->components->error('Invelo chat failed: '.$message);
            array_pop($this->conversation);

            return false;
        }

        $payload = $response->json();
        $answer = $this->extractAnswer($payload);
        $sources = $this->extractSources($payload);

        $this->line('');
        $this->components->info('Agent');
        $this->line('');
        $this->output->writeln($answer ?: '[No content returned]');

        if ($sources !== []) {
            $this->line('');
            $this->components->info('Sources');
            foreach ($sources as $source) {
                $this->output->writeln(' - '.$source);
            }
        }

        $this->conversation[] = [
            'role' => 'assistant',
            'content' => [
                ['type' => 'output_text', 'text' => $answer],
            ],
        ];

        return true;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    protected function extractAnswer(array $payload): string
    {
        $output = data_get($payload, 'output', []);

        foreach ($output as $segment) {
            $content = data_get($segment, 'content', []);

            foreach ($content as $chunk) {
                if (($chunk['type'] ?? '') === 'output_text' && isset($chunk['text'])) {
                    return (string) $chunk['text'];
                }

                if (($chunk['type'] ?? '') === 'text' && isset($chunk['text'])) {
                    return (string) $chunk['text'];
                }
            }
        }

        return (string) data_get($payload, 'output[0].content[0].text', '');
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<int, string>
     */
    protected function extractSources(array $payload): array
    {
        $sources = data_get($payload, 'web_search_call.action.sources', []);

        if (! is_array($sources)) {
            return [];
        }

        return collect($sources)
            ->map(fn ($source) => is_string($source) ? $source : json_encode($source))
            ->filter()
            ->values()
            ->all();
    }
}
