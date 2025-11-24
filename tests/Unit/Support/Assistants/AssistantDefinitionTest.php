<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Support\Assistants;

use Atlas\Nexus\Support\Assistants\AssistantDefinition;
use Atlas\Nexus\Tests\TestCase;

/**
 * Class AssistantDefinitionTest
 *
 * Verifies assistant definition payload normalization and defaults.
 */
class AssistantDefinitionTest extends TestCase
{
    public function test_it_normalizes_assistant_and_prompt_payloads(): void
    {
        $definition = new class extends AssistantDefinition
        {
            private string $descriptionValue = 'Demo description';

            private string $modelValue = 'gpt-4o';

            private float $temperatureValue = 0.25;

            private float $topPValue = 0.8;

            private int $maxTokensValue = 1024;

            private int $maxStepsValue = 6;

            private bool $promptActive = false;

            private int $promptUser = 77;

            public function key(): string
            {
                return 'demo-assistant';
            }

            public function name(): string
            {
                return 'Demo Assistant';
            }

            public function systemPrompt(): string
            {
                return 'System prompt';
            }

            /**
             * @phpstan-return string
             */
            public function description(): ?string
            {
                return $this->descriptionValue !== '' ? $this->descriptionValue : null;
            }

            /**
             * @phpstan-return string
             */
            public function model(): ?string
            {
                return $this->modelValue !== '' ? $this->modelValue : null;
            }

            /**
             * @phpstan-return float
             */
            public function temperature(): ?float
            {
                return $this->temperatureValue >= 0 ? $this->temperatureValue : null;
            }

            /**
             * @phpstan-return float
             */
            public function topP(): ?float
            {
                return $this->topPValue >= 0 ? $this->topPValue : null;
            }

            /**
             * @phpstan-return int
             */
            public function maxOutputTokens(): ?int
            {
                return $this->maxTokensValue > 0 ? $this->maxTokensValue : null;
            }

            /**
             * @phpstan-return int
             */
            public function maxDefaultSteps(): ?int
            {
                return $this->maxStepsValue > 0 ? $this->maxStepsValue : null;
            }

            public function isActive(): bool
            {
                return false;
            }

            public function isHidden(): bool
            {
                return true;
            }

            /**
             * @return array<int|string, string|array<string, mixed>>
             */
            public function tools(): array
            {
                return [
                    ' memory ',
                    'thread_fetcher' => ['mode' => 'summary'],
                    ['key' => 'thread_updater', 'config' => ['fields' => ['title']]],
                    '',
                    'memory',
                ];
            }

            /**
             * @return array<int|string, string|array<string, mixed>>
             */
            public function providerTools(): array
            {
                return [
                    ' web_search ' => ['filters' => ['allowed_domains' => ['example.com']]],
                    ['key' => 'file_search', 'options' => ['vector_store_ids' => ['vs_123']]],
                    '',
                ];
            }

            /**
             * @return array<string, mixed>
             */
            public function metadata(): array
            {
                return ['tier' => 'pro', '' => 'skip', 'segment' => 'beta'];
            }

            /**
             * @return array<string, mixed>
             */
            public function reasoning(): array
            {
                return [
                    'effort' => 'high',
                    '' => 'skip',
                    'budget_tokens' => 2048,
                ];
            }

            public function promptIsActive(): bool
            {
                return $this->promptActive;
            }

            /**
             * @phpstan-return int
             */
            public function promptUserId(): ?int
            {
                return $this->promptUser > 0 ? $this->promptUser : null;
            }
        };

        $assistant = $definition->assistantAttributes();
        $prompt = $definition->promptAttributes();

        $this->assertSame('demo-assistant', $assistant['assistant_key']);
        $this->assertSame('Demo Assistant', $assistant['name']);
        $this->assertSame('Demo description', $assistant['description']);
        $this->assertSame('gpt-4o', $assistant['default_model']);
        $this->assertSame(0.25, $assistant['temperature']);
        $this->assertSame(0.8, $assistant['top_p']);
        $this->assertSame(1024, $assistant['max_output_tokens']);
        $this->assertSame(6, $assistant['max_default_steps']);
        $this->assertFalse($assistant['is_active']);
        $this->assertTrue($assistant['is_hidden']);
        $this->assertSame(['memory', 'thread_fetcher', 'thread_updater'], $assistant['tools']);
        $this->assertSame([
            'thread_fetcher' => ['mode' => 'summary'],
            'thread_updater' => ['fields' => ['title']],
        ], $assistant['tool_configuration']);
        $this->assertSame(['web_search', 'file_search'], $assistant['provider_tools']);
        $this->assertSame([
            'web_search' => ['filters' => ['allowed_domains' => ['example.com']]],
            'file_search' => ['vector_store_ids' => ['vs_123']],
        ], $assistant['provider_tool_configuration']);
        $this->assertSame(['tier' => 'pro', 'segment' => 'beta'], $assistant['metadata']);
        $this->assertSame(['effort' => 'high', 'budget_tokens' => 2048], $assistant['reasoning']);

        $this->assertSame('System prompt', $prompt['system_prompt']);
        $this->assertFalse($prompt['is_active']);
        $this->assertSame(77, $prompt['user_id']);
    }

    public function test_it_handles_absent_optional_data(): void
    {
        $definition = new class extends AssistantDefinition
        {
            public function key(): string
            {
                return 'minimal-assistant';
            }

            public function name(): string
            {
                return 'Minimal Assistant';
            }

            public function systemPrompt(): string
            {
                return 'Prompt';
            }

            public function model(): ?string
            {
                return null;
            }
        };

        $assistant = $definition->assistantAttributes();
        $prompt = $definition->promptAttributes();

        $this->assertSame('minimal-assistant', $assistant['assistant_key']);
        $this->assertNull($assistant['tools']);
        $this->assertNull($assistant['tool_configuration']);
        $this->assertNull($assistant['provider_tools']);
        $this->assertNull($assistant['provider_tool_configuration']);
        $this->assertNull($assistant['metadata']);
        $this->assertNull($assistant['reasoning']);
        $this->assertNull($assistant['max_default_steps']);

        $this->assertSame('Prompt', $prompt['system_prompt']);
        $this->assertTrue($prompt['is_active']);
        $this->assertNull($prompt['user_id']);
    }
}
