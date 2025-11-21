<?php

declare(strict_types=1);

namespace Atlas\Nexus\Contracts;

/**
 * Interface NexusSeeder
 *
 * Defines the contract for seeding built-in or consumer-provided Nexus resources.
 */
interface NexusSeeder
{
    public function seed(): void;
}
