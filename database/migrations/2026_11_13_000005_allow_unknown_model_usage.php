<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_call_logs', function (Blueprint $table) {
            // Workspace model connection tests are not attached to a project.
            $table->unsignedBigInteger('project_id')->nullable()->change();
            foreach (['prompt_tokens', 'completion_tokens', 'total_tokens'] as $column) {
                $table->unsignedInteger($column)->nullable()->default(null)->change();
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('未知用量不能转换为零，请从升级前的数据库备份恢复。');
    }
};
