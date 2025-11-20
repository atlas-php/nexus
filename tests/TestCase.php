<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests;

use Atlas\Core\Testing\PackageTestCase;
use Atlas\Nexus\Providers\AtlasNexusServiceProvider;

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
        ];
    }
}
