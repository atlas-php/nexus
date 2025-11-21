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
        $tableName = config('atlas-nexus.tables.ai_prompts', 'ai_prompts');

        $this->schema()->create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->unsignedBigInteger('assistant_id')->index();
            $table->unsignedInteger('version');
            $table->string('label')->nullable();
            $table->text('system_prompt');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['assistant_id', 'version']);
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists(config('atlas-nexus.tables.ai_prompts', 'ai_prompts'));
    }

    protected function schema(): Builder
    {
        $connection = config('atlas-nexus.database.connection') ?: config('database.default');

        return Schema::connection($connection);
    }
};
