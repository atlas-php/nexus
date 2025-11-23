<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services\Tools;

use Atlas\Nexus\Services\Tools\ProviderToolRegistry;
use Atlas\Nexus\Support\Tools\ProviderToolDefinition;
use Atlas\Nexus\Tests\TestCase;
use Prism\Prism\ValueObjects\ProviderTool;

/**
 * Class ProviderToolRegistryTest
 *
 * Ensures provider-level tools registered at runtime are exposed for assistants.
 */
class ProviderToolRegistryTest extends TestCase
{
    public function test_it_lists_configured_provider_tools(): void
    {
        $registry = $this->app->make(ProviderToolRegistry::class);
        $registry->register('web_search', ['filters' => ['allowed_domains' => ['example.com']]]);
        $registry->register('site_search', ['filters' => ['allowed_domains' => ['example.org']]]);

        $all = $registry->all();

        $this->assertArrayHasKey('web_search', $all);
        $this->assertInstanceOf(ProviderToolDefinition::class, $all['web_search']);
        $this->assertSame(['filters' => ['allowed_domains' => ['example.com']]], $all['web_search']->options());
    }

    public function test_file_search_definition_is_converted_to_provider_tool(): void
    {
        $registry = $this->app->make(ProviderToolRegistry::class);
        $registry->register('file_search', ['vector_store_ids' => ['vs_123', '']]);

        $definition = $registry->definition('file_search');

        $this->assertInstanceOf(ProviderToolDefinition::class, $definition);

        $providerTool = $definition->toPrismProviderTool();

        $this->assertInstanceOf(ProviderTool::class, $providerTool);
        $this->assertSame('file_search', $providerTool->type);
        $this->assertSame(['vector_store_ids' => ['vs_123']], $providerTool->options);
    }

    public function test_file_search_is_skipped_when_vector_store_ids_empty(): void
    {
        $registry = $this->app->make(ProviderToolRegistry::class);
        $registry->register('file_search', ['vector_store_ids' => []]);
        $registry->register('web_search', ['filters' => ['allowed_domains' => ['example.com']]]);

        $this->assertNull($registry->definition('file_search'));
        $this->assertInstanceOf(ProviderToolDefinition::class, $registry->definition('web_search'));
    }

    public function test_code_interpreter_is_registered_with_container_options(): void
    {
        $registry = $this->app->make(ProviderToolRegistry::class);
        $registry->register('code_interpreter', ['container' => ['type' => 'auto', 'memory_limit' => '4g']]);

        $definition = $registry->definition('code_interpreter');

        $this->assertInstanceOf(ProviderToolDefinition::class, $definition);
        $this->assertSame(
            ['container' => ['type' => 'auto', 'memory_limit' => '4g']],
            $definition->options()
        );
    }

    public function test_web_search_filters_are_trimmed_and_accept_strings(): void
    {
        $registry = $this->app->make(ProviderToolRegistry::class);
        $registry->register('web_search', ['filters' => ['allowed_domains' => ' example.com , atlasphp.com ,']]);

        $definition = $registry->definition('web_search');

        $this->assertInstanceOf(ProviderToolDefinition::class, $definition);
        $this->assertSame(
            ['filters' => ['allowed_domains' => ['example.com', 'atlasphp.com']]],
            $definition->options()
        );
    }

    public function test_web_search_filters_removed_when_empty(): void
    {
        $registry = $this->app->make(ProviderToolRegistry::class);
        $registry->register('web_search', ['filters' => ['allowed_domains' => []]]);

        $definition = $registry->definition('web_search');

        $this->assertInstanceOf(ProviderToolDefinition::class, $definition);
        $this->assertSame([], $definition->options());
    }

    public function test_definition_with_options_accepts_assistant_configuration(): void
    {
        $registry = $this->app->make(ProviderToolRegistry::class);

        $definition = $registry->definitionWithOptions('file_search', ['vector_store_ids' => ['vs_999', '']]);

        $this->assertInstanceOf(ProviderToolDefinition::class, $definition);
        $this->assertSame(['vector_store_ids' => ['vs_999']], $definition->options());
    }

    public function test_definition_with_options_skips_invalid_configuration(): void
    {
        $registry = $this->app->make(ProviderToolRegistry::class);

        $this->assertNull($registry->definitionWithOptions('file_search', ['vector_store_ids' => []]));
    }
}
