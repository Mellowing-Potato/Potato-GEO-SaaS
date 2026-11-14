<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 阶段 1.4 任务自动化编排：任务运行记录表（对标 GEOFlow TaskRun）
     *
     * 每次任务触发（手动或定时）都会写入一条 TaskRun，记录运行状态、
     * 关联的技能、生成的文章、触发来源与错误信息，便于审计与重试判断。
     */
    public function up(): void
    {
        Schema::create('task_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->string('skill_id', 191);
            $table->string('status', 20)->default('pending'); // pending|running|succeeded|failed|skipped
            $table->string('trigger', 20)->default('manual'); // manual|schedule
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'task_id', 'created_at']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_runs');
    }
};
