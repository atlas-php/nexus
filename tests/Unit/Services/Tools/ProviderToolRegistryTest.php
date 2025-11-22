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
 * Ensures provider-level tools from configuration are exposed for assistants.
 */
class ProviderToolRegistryTest extends TestCase
{
    public function test_it_lists_configured_provider_tools(): void
    {
        config()->set('atlas-nexus.provider_tools', [
            'web_search' => ['filters' => ['allowed_domains' => ['example.com']]],
            'site_search' => ['filters' => ['allowed_domains' => ['example.org']]],
        ]);

        $registry = $this->app->make(ProviderToolRegistry::class);

        $all = $registry->all();

        $this->assertArrayHasKey('web_search', $all);
        $this->assertInstanceOf(ProviderToolDefinition::class, $all['web_search']);
        $this->assertSame(['filters' => ['allowed_domains' => ['example.com']]], $all['web_search']->options());
    }

    public function test_file_search_definition_is_converted_to_provider_tool(): void
    {
        config()->set('atlas-nexus.provider_tools', [
            'file_search' => ['vector_store_ids' => ['vs_123', '']],
        ]);

        $registry = $this->app->make(ProviderToolRegistry::class);

        $definition = $registry->definition('file_search');

        $this->assertInstanceOf(ProviderToolDefinition::class, $definition);

        $providerTool = $definition->toPrismProviderTool();

        $this->assertInstanceOf(ProviderTool::class, $providerTool);
        $this->assertSame('file_search', $providerTool->type);
        $this->assertSame(['vector_store_ids' => ['vs_123']], $providerTool->options);
    }

    public function test_file_search_is_skipped_when_vector_store_ids_empty(): void
    {
        config()->set('atlas-nexus.provider_tools', [
            'file_search' => ['vector_store_ids' => []],
            'web_search' => ['filters' => ['allowed_domains' => ['example.com']]],
        ]);

        $registry = $this->app->make(ProviderToolRegistry::class);

        $this->assertNull($registry->definition('file_search'));
        $this->assertInstanceOf(ProviderToolDefinition::class, $registry->definition('web_search'));
    }
}
