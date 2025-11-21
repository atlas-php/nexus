<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Fixtures;

use Atlas\Nexus\Contracts\NexusSeeder;

/**
 * Seeds nothing while tracking total executions for testing extension points.
 */
class DummyNexusSeeder implements NexusSeeder
{
    public static int $runs = 0;

    public function seed(): void
    {
        self::$runs++;
    }
}
