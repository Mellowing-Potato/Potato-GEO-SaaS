<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI 可见性监测 — 运行记录（批次二 · 单 3）。
 *
 * 设计口径（见计划书 §3.3/§10.3）：
 *  - 一行 = 一次提问（「一个问题一个 Job」红线）。多问题的一轮监测用 batch_id 聚合。
 *  - 成本五列由 geo-saas 在调用后自算落库（真实上游成本，与客户扣点两笔数分开记）。
 *  - 迁移按 Postgres 校验（生产 pg16；CI sqlite 也要能跑），不用 ->after() 等 PG 无效写法。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_visibility_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('project_id');
            $table->unsignedBigInteger('user_id');
            // 一轮监测（一次提交的 N 个问题）共享同一个 batch_id；单问题提交也生成，方便统一按批查询。
            $table->string('batch_id', 36);
            // 问题在批次内的序号（0 起），用于「第 3/10 个问题」进度展示。
            $table->unsignedSmallInteger('question_index')->default(0);
            $table->text('question');
            $table->string('status', 20)->default('pending');
            $table->string('provider', 50)->default('doubao');
            $table->string('model', 100)->default('doubao-seed-evolving');
            // 客户计价用的网关商品 id。显式落列＝「按 geo-reason 近似计价」是写在库里的选择
            // （计划书 §10.3 第 1 条），不是静默回落。
            $table->string('billing_model', 50)->default('geo-reason');

            $table->text('answer_text')->nullable();

            // ── 真实上游成本（§10.1 单价 × 实际用量，geo-saas 自算）──
            $table->decimal('upstream_cost_fen', 10, 2)->default(0);
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->unsignedInteger('cached_tokens')->default(0);
            $table->unsignedInteger('search_call_count')->default(0);

            // 本次向客户扣的点（分，来自网关扣点回执）。失败/未扣为 null 与 0 区分。
            $table->decimal('points_charged_fen', 10, 2)->nullable();

            $table->decimal('duration_seconds', 8, 2)->default(0);
            $table->text('error_message')->nullable();
            // 搜索词列表、成本明细拆解、扣点回执快照等。logo_url 这类临时签名链接只进这里，不设永久列。
            $table->json('metadata')->nullable();

            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'created_at'], 'avr_tenant_created');
            $table->index(['tenant_id', 'status'], 'avr_tenant_status');
            $table->index(['tenant_id', 'project_id'], 'avr_tenant_project');
            $table->index(['tenant_id', 'batch_id'], 'avr_tenant_batch');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_visibility_runs');
    }
};
