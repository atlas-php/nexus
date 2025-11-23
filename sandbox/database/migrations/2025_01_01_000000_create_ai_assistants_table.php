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
        $tableName = config('atlas-nexus.database.tables.ai_assistants', 'ai_assistants');

        $this->schema()->create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('default_model')->nullable();
            $table->decimal('temperature', 3, 2)->nullable();
            $table->decimal('top_p', 3, 2)->nullable();
            $table->unsignedInteger('max_output_tokens')->nullable();
            $table->unsignedBigInteger('current_prompt_id')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->boolean('is_hidden')->default(false)->index();
            $table->json('provider_tools')->nullable();
            $table->json('tools')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('current_prompt_id');
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists(config('atlas-nexus.database.tables.ai_assistants', 'ai_assistants'));
    }

    protected function schema(): Builder
    {
        $connection = config('atlas-nexus.database.connection') ?: config('database.default');

        return Schema::connection($connection);
    }
};
