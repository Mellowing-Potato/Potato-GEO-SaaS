<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 阶段 1.4 任务自动化编排：任务定义表（对标 GEOFlow Task / TaskSchedule）
     *
     * 一个任务绑定「技能 + 项目 + 节奏 + 发布/分发开关」，到点后由 tasks:tick 派发 RunTaskJob，
     * 串联：技能执行 → 生成草稿 →（可选）发布 →（可选）按项目绑定渠道分发。
     */
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('skill_id', 191);
            $table->string('cadence', 20)->default('daily'); // hourly|daily|weekly|monthly
            $table->string('timezone', 64)->default('Asia/Shanghai');
            $table->text('query')->nullable();
            $table->json('input_data')->nullable();
            $table->boolean('auto_publish')->default(false);
            $table->boolean('auto_distribute')->default(true);
            $table->string('status', 20)->default('active'); // active|paused
            $table->timestamp('last_run_at')->nullable();
            $table->timestamp('next_run_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status', 'next_run_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tasks');
    }
};
