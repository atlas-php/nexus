<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services\Tools;

use Atlas\Nexus\Services\Tools\ProviderToolRegistry;
use Atlas\Nexus\Support\Tools\ProviderToolDefinition;
use Atlas\Nexus\Tests\TestCase;

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
}
