<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Agents;

use Atlas\Nexus\Services\Agents\Definitions\GeneralAgent;
use Atlas\Nexus\Tests\TestCase;

/**
 * Class GeneralAgentTest
 *
 * Confirms the bundled general agent exposes the expected reasoning defaults.
 */
class GeneralAgentTest extends TestCase
{
    public function test_it_configures_low_reasoning_effort(): void
    {
        $assistant = new GeneralAgent;

        $this->assertSame([
            'effort' => 'low',
        ], $assistant->reasoning());
    }
}
