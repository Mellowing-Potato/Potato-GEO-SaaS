<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name')->comment('项目名称');
            $table->string('brand_name')->nullable()->comment('品牌名称');
            $table->string('brand_url')->nullable()->comment('品牌官网');
            $table->unsignedTinyInteger('current_stage')->default(0)->comment('当前阶段 0-5');
            $table->unsignedTinyInteger('progress')->default(0)->comment('整体进度 0-100');
            $table->string('status', 20)->default('active')->comment('active/paused/completed');
            $table->json('settings')->nullable()->comment('项目配置（竞品、AI 平台等）');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'status']);
            $table->index('owner_id');
        });

        Schema::create('project_stages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->unsignedTinyInteger('stage_index')->comment('阶段索引 0-5');
            $table->string('stage_key', 30)->comment('阶段标识: research/strategy/production/distribution/monitoring/iteration');
            $table->string('status', 20)->default('pending')->comment('pending/active/completed/skipped');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->json('metadata')->nullable()->comment('阶段数据（关键词、诊断结果等）');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'stage_index']);
            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_stages');
        Schema::dropIfExists('projects');
    }
};
