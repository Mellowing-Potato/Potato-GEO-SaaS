<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 手动发布 · 发布单（Publication）。
 *
 * 状态机（七态、三可重开）：
 *   draft → ready → in_progress → completed
 *                             ↘ failed / skipped / cancelled （可重开 → ready）
 * 每次状态变更必须在 manual_publication_transitions 留轨迹（核心证据价值）。
 *
 * 外键统一指向 users（§一-3）。索引显式命名，避免 PG 63 字节截断风险。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('manual_publications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');

            $table->string('type', 16)->default('post');
            $table->unsignedBigInteger('article_id')->nullable();
            $table->unsignedBigInteger('persona_id');
            $table->unsignedBigInteger('account_id')->nullable();
            $table->unsignedBigInteger('assigned_user_id')->nullable();
            $table->unsignedBigInteger('created_by_user_id');

            $table->string('platform', 32);
            $table->string('custom_platform')->nullable();
            $table->text('target_url')->nullable();
            $table->string('target_url_hash', 64)->nullable();
            $table->text('target_context')->nullable();

            $table->text('content');
            $table->char('content_fingerprint', 64);
            $table->json('source_snapshot')->nullable();
            $table->json('identity_snapshot')->nullable();
            $table->text('disclosure_snapshot')->nullable();

            $table->string('risk_status', 16)->default('clean');
            $table->json('risk_result')->nullable();
            $table->unsignedInteger('duplicate_warning_count')->default(0);

            $table->timestamp('scheduled_at')->nullable();
            $table->string('status', 16)->default('draft');
            $table->timestamp('status_changed_at')->nullable();
            $table->text('completion_url')->nullable();
            $table->text('result_note')->nullable();
            $table->timestamp('completed_at')->nullable();

            $table->unsignedInteger('revision')->default(1);

            $table->timestamps();

            // 跨租户/列表常用组合，显式命名（避免 Laravel 自动生成 >62 字节）
            $table->index(['tenant_id', 'status'], 'mp_tenant_status');
            $table->index(['tenant_id', 'assigned_user_id', 'status', 'scheduled_at'], 'mp_assignee_status_schedule');
            $table->index(['tenant_id', 'article_id'], 'mp_tenant_article');
            $table->index(['tenant_id', 'persona_id'], 'mp_tenant_persona');
            $table->index(['tenant_id', 'account_id'], 'mp_tenant_account');
            $table->index(['tenant_id', 'platform', 'content_fingerprint'], 'mp_tenant_platform_fp');
        });
    }

    public  function down(): void
    {
        Schema::dropIfExists('manual_publications');
    }
};
