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
        $tableName = config('atlas-nexus.database.tables.ai_threads', 'ai_threads');

        $this->schema()->create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('group_id')->nullable()->index();
            $table->string('assistant_key')->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('type');
            $table->unsignedBigInteger('parent_thread_id')->nullable()->index();
            $table->unsignedBigInteger('parent_tool_run_id')->nullable()->index();
            $table->string('title')->nullable();
            $table->string('status');
            $table->text('summary')->nullable();
            $table->text('prompt_snapshot')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->unsignedBigInteger('last_summary_message_id')->nullable()->index();
            $table->json('metadata')->nullable();
            $table->softDeletes();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists(config('atlas-nexus.database.tables.ai_threads', 'ai_threads'));
    }

    protected function schema(): Builder
    {
        $connection = config('atlas-nexus.database.connection') ?: config('database.default');

        return Schema::connection($connection);
    }
};
