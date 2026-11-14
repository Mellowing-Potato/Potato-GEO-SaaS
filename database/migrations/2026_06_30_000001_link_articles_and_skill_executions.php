<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->string('source_type', 30)->default('manual')->after('status');
            $table->foreignId('skill_execution_id')
                ->nullable()
                ->after('source_skill')
                ->constrained('skill_executions')
                ->nullOnDelete();

            $table->index(['project_id', 'source_type']);
            $table->index('skill_execution_id');
        });

        Schema::table('skill_executions', function (Blueprint $table) {
            $table->foreignId('article_id')
                ->nullable()
                ->after('quality_report')
                ->constrained('articles')
                ->nullOnDelete();

            $table->index('article_id');
        });
    }

    public function down(): void
    {
        Schema::table('skill_executions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('article_id');
        });

        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex(['project_id', 'source_type']);
            $table->dropConstrainedForeignId('skill_execution_id');
            $table->dropColumn('source_type');
        });
    }
};
