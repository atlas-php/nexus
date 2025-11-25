<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Feature\Assistants;

use Atlas\Nexus\Assistants\GeneralAssistant;
use Atlas\Nexus\Assistants\HumanAssistant;
use Atlas\Nexus\Assistants\MemoryAssistant;
use Atlas\Nexus\Assistants\ThreadManagerAssistant;
use Atlas\Nexus\Services\Assistants\AssistantRegistry;
use Atlas\Nexus\Tests\TestCase;

/**
 * Class DefaultAssistantsTest
 *
 * Ensures the package registers its built-in assistant definitions so consumers have defaults without custom code.
 */
class DefaultAssistantsTest extends TestCase
{
    protected function shouldUseAssistantFixtures(): bool
    {
        return false;
    }

    public function test_it_registers_built_in_assistants_by_default(): void
    {
        $expected = [
            GeneralAssistant::class,
            HumanAssistant::class,
            ThreadManagerAssistant::class,
            MemoryAssistant::class,
        ];

        $this->assertSame($expected, config('atlas-nexus.assistants'));

        $registry = $this->app->make(AssistantRegistry::class);

        $this->assertSame(
            ['general-assistant', 'human-assistant', 'thread-manager', 'memory-assistant'],
            $registry->keys()
        );
    }
}
