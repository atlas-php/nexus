<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Support\Assistants;

use Atlas\Nexus\Services\Assistants\AssistantDefinition;
use Atlas\Nexus\Services\Assistants\ResolvedAssistant;
use Atlas\Nexus\Tests\TestCase;

/**
 * Class ResolvedAssistantTest
 *
 * Ensures assistant configuration payloads are exposed for runtime services.
 */
class ResolvedAssistantTest extends TestCase
{
    public function test_it_exposes_configuration_maps(): void
    {
        $definition = new class extends AssistantDefinition
        {
            private int $maxSteps = 5;

            private ?string $modelKey = 'gpt-test';

            public function key(): string
            {
                return 'configurable';
            }

            public function name(): string
            {
                return 'Configurable';
            }

            public function systemPrompt(): string
            {
                return 'Prompt';
            }

            public function clearModel(): void
            {
                $this->modelKey = null;
            }

            public function model(): ?string
            {
                return $this->modelKey;
            }

            public function maxDefaultSteps(): ?int
            {
                return $this->maxSteps > 0 ? $this->maxSteps : null;
            }

            /**
             * @return array<int|string, string|array<string, mixed>>
             */
            public function tools(): array
            {
                return [
                    'memory',
                    ['key' => 'fetch_more_context', 'config' => ['limit' => 10]],
                ];
            }

            /**
             * @return array<int|string, string|array<string, mixed>>
             */
            public function providerTools(): array
            {
                return [
                    'file_search' => ['vector_store_ids' => ['vs_42']],
                ];
            }
        };

        $resolved = new ResolvedAssistant($definition);

        $this->assertSame(5, $resolved->maxDefaultSteps());
        $this->assertSame(['limit' => 10], $resolved->toolConfiguration('fetch_more_context'));
        $this->assertSame(['vector_store_ids' => ['vs_42']], $resolved->providerToolConfiguration('file_search'));
        $this->assertNull($resolved->toolConfiguration('memory'));
        $this->assertSame(['fetch_more_context' => ['limit' => 10]], $resolved->toolConfigurations());
        $this->assertSame(['file_search' => ['vector_store_ids' => ['vs_42']]], $resolved->providerToolConfigurations());
    }
}
