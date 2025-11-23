<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services\Threads;

use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Threads\AssistantResponseService;
use Atlas\Nexus\Services\Threads\ThreadStateService;
use Atlas\Nexus\Services\Tools\ToolRegistry;
use Atlas\Nexus\Support\Tools\ToolDefinition;
use Atlas\Nexus\Tests\Fixtures\Assistants\PrimaryAssistantDefinition;
use Atlas\Nexus\Tests\Fixtures\ConfigurableStubTool;
use Atlas\Nexus\Tests\TestCase;
use ReflectionMethod;

class AssistantResponseServiceConfigTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ConfigurableStubTool::reset();

        $this->app->make(ToolRegistry::class)->register(
            new ToolDefinition('configurable_tool', ConfigurableStubTool::class)
        );

        $this->loadPackageMigrations($this->migrationPath());
        $this->runPendingCommand('migrate:fresh', [
            '--path' => $this->migrationPath(),
            '--realpath' => true,
        ])->run();
    }

    public function test_it_applies_assistant_tool_configuration_to_configurable_tools(): void
    {
        PrimaryAssistantDefinition::updateConfig([
            'tools' => [
                'configurable_tool' => ['mode' => 'summary', 'depth' => 2],
            ],
        ]);

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        $state = $this->app->make(ThreadStateService::class)->forThread($thread->fresh());
        $service = $this->app->make(AssistantResponseService::class);

        $method = new ReflectionMethod(AssistantResponseService::class, 'prepareTools');
        $method->setAccessible(true);
        $method->invoke($service, $state, 123);

        $this->assertSame(
            ['mode' => 'summary', 'depth' => 2],
            ConfigurableStubTool::$appliedConfiguration
        );
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../../database/migrations';
    }
}
