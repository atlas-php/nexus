<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Assistants;

use Atlas\Nexus\Services\Assistants\Definitions\GeneralAssistant;
use Atlas\Nexus\Tests\TestCase;

/**
 * Class GeneralAssistantTest
 *
 * Confirms the bundled general assistant exposes the expected reasoning defaults.
 */
class GeneralAssistantTest extends TestCase
{
    public function test_it_configures_low_reasoning_effort(): void
    {
        $assistant = new GeneralAssistant;

        $this->assertSame([
            'effort' => 'low',
        ], $assistant->reasoning());
    }
}
