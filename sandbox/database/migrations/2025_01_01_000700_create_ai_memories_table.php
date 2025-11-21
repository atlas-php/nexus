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
        $tableName = config('atlas-nexus.tables.ai_memories', 'ai_memories');

        $this->schema()->create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('group_id')->nullable()->index();
            $table->string('owner_type');
            $table->unsignedBigInteger('owner_id');
            $table->unsignedBigInteger('assistant_id')->nullable()->index();
            $table->unsignedBigInteger('thread_id')->nullable()->index();
            $table->unsignedBigInteger('source_message_id')->nullable()->index();
            $table->unsignedBigInteger('source_tool_run_id')->nullable()->index();
            $table->string('kind');
            $table->text('content');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['owner_type', 'owner_id']);
            $table->index('kind');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists(config('atlas-nexus.tables.ai_memories', 'ai_memories'));
    }

    protected function schema(): Builder
    {
        $connection = config('atlas-nexus.database.connection') ?: config('database.default');

        return Schema::connection($connection);
    }
};
