<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services\Assistants;

use Atlas\Nexus\Services\Assistants\AssistantRegistry;
use Atlas\Nexus\Services\Assistants\ResolvedAssistant;
use Atlas\Nexus\Tests\Fixtures\Assistants\PrimaryAssistantDefinition;
use Atlas\Nexus\Tests\Fixtures\Assistants\SecondaryAssistantDefinition;
use Atlas\Nexus\Tests\TestCase;
use RuntimeException;

/**
 * Class AssistantRegistryTest
 *
 * Ensures the assistant registry exposes configured assistants and refreshes when config changes.
 */
class AssistantRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('atlas-nexus.assistants', [
            PrimaryAssistantDefinition::class,
            SecondaryAssistantDefinition::class,
        ]);
    }

    public function test_it_returns_all_resolved_assistants(): void
    {
        /** @var AssistantRegistry $registry */
        $registry = $this->app->make(AssistantRegistry::class);
        $registry->refresh();

        $all = $registry->all();

        $this->assertCount(2, $all);
        $this->assertContainsOnlyInstancesOf(ResolvedAssistant::class, $all);
        $this->assertArrayHasKey('general-assistant', $all);
        $this->assertArrayHasKey('secondary-assistant', $all);
    }

    public function test_it_returns_assistant_keys(): void
    {
        /** @var AssistantRegistry $registry */
        $registry = $this->app->make(AssistantRegistry::class);
        $registry->refresh();

        $this->assertSame(
            ['general-assistant', 'secondary-assistant'],
            $registry->keys()
        );
    }

    public function test_find_returns_assistant_case_insensitive(): void
    {
        /** @var AssistantRegistry $registry */
        $registry = $this->app->make(AssistantRegistry::class);
        $registry->refresh();

        $assistant = $registry->find('GENERAL-ASSISTANT');

        $this->assertInstanceOf(ResolvedAssistant::class, $assistant);
        $this->assertSame('general-assistant', $assistant->key());
    }

    public function test_require_throws_for_missing_assistant(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Assistant definition [missing] is not registered.');

        /** @var AssistantRegistry $registry */
        $registry = $this->app->make(AssistantRegistry::class);
        $registry->refresh();
        $registry->require('missing');
    }

    public function test_refresh_reloads_from_config(): void
    {
        /** @var AssistantRegistry $registry */
        $registry = $this->app->make(AssistantRegistry::class);
        $registry->refresh();

        $this->assertSame(['general-assistant', 'secondary-assistant'], $registry->keys());

        config()->set('atlas-nexus.assistants', [
            SecondaryAssistantDefinition::class,
        ]);

        $registry->refresh();

        $this->assertSame(['secondary-assistant'], $registry->keys());
    }
}
