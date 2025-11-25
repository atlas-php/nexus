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
        $tableName = config('atlas-nexus.database.tables.ai_messages', 'ai_messages');

        $this->schema()->create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('group_id')->nullable()->index();
            $table->unsignedBigInteger('thread_id');
            $table->string('assistant_key')->index();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('role');
            $table->text('content');
            $table->string('content_type');
            $table->longText('raw_response')->nullable();
            $table->unsignedInteger('sequence');
            $table->string('status')->default('completed');
            $table->boolean('is_memory_checked')->default(false)->index();
            $table->boolean('is_context_prompt')->default(false);
            $table->text('failed_reason')->nullable();
            $table->string('model')->nullable();
            $table->unsignedInteger('tokens_in')->nullable();
            $table->unsignedInteger('tokens_out')->nullable();
            $table->string('provider_response_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['thread_id', 'sequence']);
            $table->index('thread_id');
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists(config('atlas-nexus.database.tables.ai_messages', 'ai_messages'));
    }

    protected function schema(): Builder
    {
        $connection = config('atlas-nexus.database.connection') ?: config('database.default');

        return Schema::connection($connection);
    }
};
