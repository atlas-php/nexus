<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit;

use Atlas\Nexus\Integrations\Prism\TextRequest;
use Atlas\Nexus\NexusManager;
use Atlas\Nexus\Services\Threads\Logging\ChatThreadLog;
use Atlas\Nexus\Tests\TestCase;

/**
 * Class NexusManagerTest
 *
 * Validates the baseline configuration utilities exposed by the Nexus manager.
 * PRD Reference: Atlas Nexus Foundation Setup — Configuration resolution.
 */
class NexusManagerTest extends TestCase
{
    public function test_it_exposes_prism_text_requests(): void
    {
        $manager = $this->app->make(NexusManager::class);
        $threadLog = new ChatThreadLog;

        $request = $manager->text($threadLog);

        $this->assertInstanceOf(TextRequest::class, $request);
        $this->assertSame($threadLog, $request->chatThreadLog());
    }
}
