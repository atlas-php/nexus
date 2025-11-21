<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\Builder;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tableName = config('atlas-nexus.tables.ai_tool_runs', 'ai_tool_runs');

        $this->schema()->create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('group_id')->nullable()->index();
            $table->string('tool_key')->index();
            $table->unsignedBigInteger('thread_id')->index();
            $table->unsignedBigInteger('assistant_message_id')->index();
            $table->unsignedInteger('call_index');
            $table->json('input_args');
            $table->string('status');
            $table->json('response_output')->nullable();
            $table->json('metadata')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists(config('atlas-nexus.tables.ai_tool_runs', 'ai_tool_runs'));
    }

    protected function schema(): Builder
    {
        $connection = config('atlas-nexus.database.connection') ?: config('database.default');

        return Schema::connection($connection);
    }
};
