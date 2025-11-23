<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests;

use Atlas\Core\Testing\PackageTestCase;
use Atlas\Nexus\Providers\AtlasNexusServiceProvider;
use Atlas\Nexus\Services\Assistants\AssistantRegistry;
use Atlas\Nexus\Tests\Fixtures\Assistants\PrimaryAssistantDefinition;
use Atlas\Nexus\Tests\Fixtures\Assistants\SecondaryAssistantDefinition;
use Atlas\Nexus\Tests\Fixtures\Assistants\ThreadManagerAssistantDefinition;
use Atlas\Nexus\Tests\Fixtures\Assistants\WebSummaryAssistantDefinition;
use Prism\Prism\PrismServiceProvider;

/**
 * Class TestCase
 *
 * Boots Orchestra Testbench with the Atlas Nexus service provider for package feature coverage.
 * PRD Reference: Atlas Nexus Foundation Setup — Testing harness requirements.
 *
 * @property \Illuminate\Foundation\Application $app
 */
abstract class TestCase extends PackageTestCase
{
    /**
     * @param  \Illuminate\Foundation\Application  $app
     */
    protected function getPackageProviders($app): array
    {
        return [
            AtlasNexusServiceProvider::class,
            PrismServiceProvider::class,
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();

        PrimaryAssistantDefinition::resetConfig();
        SecondaryAssistantDefinition::resetConfig();
        ThreadManagerAssistantDefinition::resetConfig();
        WebSummaryAssistantDefinition::resetConfig();

        $assistants = [
            PrimaryAssistantDefinition::class,
            SecondaryAssistantDefinition::class,
            ThreadManagerAssistantDefinition::class,
            WebSummaryAssistantDefinition::class,
        ];

        config()->set('atlas-nexus.assistants', $assistants);

        $this->app->make(AssistantRegistry::class)->refresh();
    }
}
