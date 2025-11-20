<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit;

use Atlas\Nexus\NexusManager;
use Atlas\Nexus\Support\Chat\ChatThreadLog;
use Atlas\Nexus\Tests\TestCase;
use Atlas\Nexus\Text\TextRequest;
use InvalidArgumentException;

/**
 * Class NexusManagerTest
 *
 * Validates the baseline configuration utilities exposed by the Nexus manager.
 * PRD Reference: Atlas Nexus Foundation Setup — Configuration resolution.
 */
class NexusManagerTest extends TestCase
{
    public function test_it_returns_pipeline_configuration(): void
    {
        $this->app['config']->set('atlas-nexus.pipelines', [
            'alpha' => ['model' => 'gpt-5'],
        ]);

        $manager = $this->app->make(NexusManager::class);

        $this->assertSame(['model' => 'gpt-5'], $manager->getPipelineConfig('alpha'));
    }

    public function test_it_throws_when_pipeline_missing(): void
    {
        $this->app['config']->set('atlas-nexus.pipelines', []);

        $manager = $this->app->make(NexusManager::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Pipeline [missing] is not defined.');

        $manager->getPipelineConfig('missing');
    }

    public function test_it_exposes_prism_text_requests(): void
    {
        $manager = $this->app->make(NexusManager::class);
        $threadLog = new ChatThreadLog;

        $request = $manager->text($threadLog);

        $this->assertInstanceOf(TextRequest::class, $request);
        $this->assertSame($threadLog, $request->chatThreadLog());
    }
}
