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
        $tableName = config('atlas-nexus.tables.ai_tools', 'ai_tools');

        $this->schema()->create($tableName, function (Blueprint $table): void {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->json('schema');
            $table->string('handler_class');
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        $this->schema()->dropIfExists(config('atlas-nexus.tables.ai_tools', 'ai_tools'));
    }

    protected function schema(): Builder
    {
        $connection = config('atlas-nexus.database.connection') ?: config('database.default');

        return Schema::connection($connection);
    }
};
