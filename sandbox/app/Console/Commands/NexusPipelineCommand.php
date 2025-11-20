<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Atlas\Nexus\NexusManager;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use InvalidArgumentException;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Streaming\Events\ErrorEvent;
use Prism\Prism\Streaming\Events\StreamEvent;
use Prism\Prism\Streaming\Events\TextDeltaEvent;
use Throwable;

/**
 * Provides an artisan entry point for executing Nexus pipelines with Prism providers, enabling rapid CLI experiments.
 */
class NexusPipelineCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'nexus:pipeline
        {prompt? : The user message to send to the pipeline}
        {--pipeline= : Override the pipeline key configured in atlas-nexus.php}
        {--system= : Provide a system prompt override}
        {--stream : Stream tokens to the console instead of waiting for completion}';

    /**
     * @var string
     */
    protected $description = 'Run an Atlas Nexus pipeline locally using Prism-backed AI providers.';

    public function __construct(
        private readonly NexusManager $nexusManager
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $pipelineName = $this->option('pipeline')
            ?? $this->nexusManager->getDefaultPipelineName()
            ?? 'default';

        try {
            $pipeline = $this->nexusManager->getPipelineConfig($pipelineName);
        } catch (InvalidArgumentException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $prompt = (string) ($this->argument('prompt') ?? '');

        if ($prompt === '') {
            $prompt = (string) $this->ask('Enter a prompt for the pipeline');
        }

        if ($prompt === '') {
            $this->components->error('A prompt is required to run the pipeline.');

            return self::FAILURE;
        }

        $provider = (string) ($pipeline['provider'] ?? env('PRISM_DEFAULT_PROVIDER', 'openai'));
        $model = (string) ($pipeline['model'] ?? env('PRISM_DEFAULT_MODEL', 'gpt-4o-mini'));
        $systemPrompt = (string) ($this->option('system') ?? Arr::get($pipeline, 'system_prompt', ''));
        $providerConfig = Arr::get($pipeline, 'provider_config', []);

        if (! is_array($providerConfig)) {
            $this->components->error('Pipeline provider configuration must be an array.');

            return self::FAILURE;
        }

        $providerConfig = array_filter($providerConfig, static fn ($value): bool => ! (is_null($value) || $value === ''));

        $pending = Prism::text()
            ->using($provider, $model, $providerConfig)
            ->withMaxSteps($this->maxSteps());

        if ($systemPrompt !== '') {
            $pending->withSystemPrompt($systemPrompt);
        }

        $pending->withPrompt($prompt);

        try {
            if ($this->option('stream')) {
                $this->streamResponse($pending->asStream());
            } else {
                $response = $pending->asText();

                if ($response === null) {
                    $this->components->error('Prism did not return a response.');

                    return self::FAILURE;
                }

                $this->line('');
                $this->components->info($response->text);
                $this->newLine();
            }
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * @param  iterable<int, StreamEvent>  $chunks
     */
    protected function streamResponse(iterable $chunks): void
    {
        $this->line('');

        foreach ($chunks as $chunk) {
            if ($chunk instanceof TextDeltaEvent) {
                $this->output->write($chunk->delta);
            }

            if ($chunk instanceof ErrorEvent) {
                $this->components->error($chunk->message);

                break;
            }
        }

        $this->newLine(2);
    }

    protected function maxSteps(): int
    {
        return (int) env('PRISM_MAX_STEPS', 8);
    }
}
