<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Prism\Prism\Facades\Prism;
use Throwable;

/**
 * Offers a lightweight CLI harness for Prism text completions so prompts can be evaluated without full pipeline context.
 */
class PrismTextCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'prism:text
        {message : The user message sent to the Prism provider}
        {--provider= : Override the Prism provider key (defaults to env PRISM_DEFAULT_PROVIDER)}
        {--model= : Override the model name (defaults to env PRISM_DEFAULT_MODEL)}
        {--system= : Optional system prompt to prepend to the conversation}';

    /**
     * @var string
     */
    protected $description = 'Send a simple prompt to Prism and output the resulting completion.';

    public function handle(): int
    {
        $provider = (string) ($this->option('provider') ?? env('PRISM_DEFAULT_PROVIDER', 'openai'));
        $model = (string) ($this->option('model') ?? env('PRISM_DEFAULT_MODEL', 'gpt-4o-mini'));
        $system = (string) ($this->option('system') ?? env('PRISM_SYSTEM_PROMPT', ''));

        $pending = Prism::text()
            ->using($provider, $model)
            ->withMaxSteps($this->maxSteps());

        if ($system !== '') {
            $pending->withSystemPrompt($system);
        }

        $pending->withPrompt((string) $this->argument('message'));

        try {
            $response = $pending->asText();
        } catch (Throwable $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($response === null) {
            $this->components->error('Prism did not return a response.');

            return self::FAILURE;
        }

        $this->line('');
        $this->components->info($response->text);
        $this->newLine();

        return self::SUCCESS;
    }

    protected function maxSteps(): int
    {
        return (int) env('PRISM_MAX_STEPS', 8);
    }
}
