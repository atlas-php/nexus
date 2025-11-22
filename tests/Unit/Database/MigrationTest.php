<?php

declare(strict_types=1);

namespace Atlas\Nexus\Tests\Unit\Database;

use Atlas\Nexus\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Class MigrationTest
 *
 * Verifies Nexus migrations align with the PRD-defined schema and honor configurable table names.
 * PRD Reference: Atlas Nexus Overview — Database schema section.
 */
class MigrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadPackageMigrations($this->migrationPath());
    }

    public function test_migrations_create_all_tables_with_expected_columns(): void
    {
        $this->runPendingCommand('migrate:fresh', [
            '--path' => $this->migrationPath(),
            '--realpath' => true,
        ])->run();

        $this->assertTrue(Schema::hasColumns('ai_assistants', [
            'id',
            'slug',
            'name',
            'description',
            'default_model',
            'temperature',
            'top_p',
            'max_output_tokens',
            'current_prompt_id',
            'is_active',
            'is_hidden',
            'tools',
            'metadata',
            'created_at',
            'updated_at',
            'deleted_at',
        ]));

        $this->assertTrue(Schema::hasColumns('ai_prompts', [
            'id',
            'user_id',
            'assistant_id',
            'version',
            'label',
            'system_prompt',
            'is_active',
            'created_at',
            'updated_at',
            'deleted_at',
        ]));

        $this->assertTrue(Schema::hasColumns('ai_threads', [
            'id',
            'assistant_id',
            'user_id',
            'type',
            'parent_thread_id',
            'parent_tool_run_id',
            'title',
            'status',
            'prompt_id',
            'summary',
            'last_message_at',
            'metadata',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('ai_messages', [
            'id',
            'thread_id',
            'user_id',
            'role',
            'content',
            'content_type',
            'raw_response',
            'sequence',
            'status',
            'failed_reason',
            'model',
            'tokens_in',
            'tokens_out',
            'provider_response_id',
            'metadata',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('ai_tool_runs', [
            'id',
            'tool_key',
            'thread_id',
            'assistant_message_id',
            'call_index',
            'input_args',
            'status',
            'response_output',
            'metadata',
            'error_message',
            'started_at',
            'finished_at',
            'created_at',
            'updated_at',
        ]));

        $this->assertTrue(Schema::hasColumns('ai_memories', [
            'id',
            'owner_type',
            'owner_id',
            'assistant_id',
            'thread_id',
            'source_message_id',
            'source_tool_run_id',
            'kind',
            'content',
            'metadata',
            'created_at',
            'updated_at',
        ]));
    }

    public function test_migrations_respect_configured_table_overrides(): void
    {
        config()->set('atlas-nexus.tables.ai_assistants', 'custom_ai_assistants');

        $this->assertSame('custom_ai_assistants', config('atlas-nexus.tables.ai_assistants'));

        $this->runPendingCommand('migrate:fresh', [
            '--path' => $this->migrationPath(),
            '--realpath' => true,
        ])->run();

        $tables = collect(DB::select('select name from sqlite_master where type = "table"'))
            ->pluck('name')
            ->all();

        $this->assertContains('custom_ai_assistants', $tables);
        $this->assertNotContains('ai_assistants', $tables);
        $this->assertTrue(Schema::hasTable('ai_tool_runs'));
    }

    private function migrationPath(): string
    {
        return __DIR__.'/../../../database/migrations';
    }
}
