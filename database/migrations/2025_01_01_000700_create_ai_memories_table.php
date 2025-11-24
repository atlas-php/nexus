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
        $tableName = config('atlas-nexus.database.tables.ai_memories', 'ai_memories');

        $this->schema()->create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('group_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('assistant_key')->index();
            $table->unsignedBigInteger('thread_id')->nullable()->index();
            $table->string('content', 255);
            $table->json('source_message_ids')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists(config('atlas-nexus.database.tables.ai_memories', 'ai_memories'));
    }

    protected function schema(): Builder
    {
        $connection = config('atlas-nexus.database.connection') ?: config('database.default');

        return Schema::connection($connection);
    }
};
