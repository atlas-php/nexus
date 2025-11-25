<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Services\Threads;

use Atlas\Nexus\Models\AiThread;
use Atlas\Nexus\Services\Threads\AssistantResponseService;
use Atlas\Nexus\Services\Threads\ThreadStateService;
use Atlas\Nexus\Services\Tools\ToolDefinition;
use Atlas\Nexus\Services\Tools\ToolRegistry;
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
        PrimaryAssistantDefinition::resetConfig();

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

        $state = $this->app->make(ThreadStateService::class)->forThread($thread->refresh());
        $service = $this->app->make(AssistantResponseService::class);

        $method = new ReflectionMethod(AssistantResponseService::class, 'prepareTools');
        $method->setAccessible(true);
        $method->invoke($service, $state, 123);

        $this->assertSame(
            ['mode' => 'summary', 'depth' => 2],
            ConfigurableStubTool::$appliedConfiguration
        );
    }

    public function test_it_applies_openai_reasoning_options_when_configured(): void
    {
        PrimaryAssistantDefinition::updateConfig([
            'reasoning' => [
                'effort' => 'medium',
                'budget_tokens' => 512,
            ],
        ]);

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        $state = $this->app->make(ThreadStateService::class)->forThread($thread->refresh());
        $service = $this->app->make(AssistantResponseService::class);

        $method = new ReflectionMethod(AssistantResponseService::class, 'resolveProviderOptions');
        $method->setAccessible(true);
        $options = $method->invoke($service, $state, 'openai');

        $this->assertSame([
            'reasoning' => [
                'effort' => 'medium',
                'budget_tokens' => 512,
            ],
        ], $options);
    }

    public function test_it_skips_reasoning_options_for_non_openai_providers(): void
    {
        PrimaryAssistantDefinition::updateConfig([
            'reasoning' => [
                'effort' => 'high',
            ],
        ]);

        /** @var AiThread $thread */
        $thread = AiThread::factory()->create([
            'assistant_key' => 'general-assistant',
        ]);

        $state = $this->app->make(ThreadStateService::class)->forThread($thread->refresh());
        $service = $this->app->make(AssistantResponseService::class);

        $method = new ReflectionMethod(AssistantResponseService::class, 'resolveProviderOptions');
        $method->setAccessible(true);
        $options = $method->invoke($service, $state, 'anthropic');

        $this->assertSame([], $options);
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../../database/migrations';
    }
}
