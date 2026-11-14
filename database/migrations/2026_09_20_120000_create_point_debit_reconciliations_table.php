<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * GEO 用量副本 ↔ 网关权威账本的每日对账结果（计划 GAP-06）。
 *
 * 为什么需要一张独立表而不是复用商业事件：这里记的是**账务差异**，
 * 不是商业订单状态流转。三类差异（仅本端 / 仅网关端 / 金额不等）加上
 * "本端受阻未扣费"一共四种，都要进后台待办、都要能挂人工复核结论。
 *
 * 表刻意不加 TenantScope：对账是跨租户的运维动作（与 ModelCallLog 用
 * withoutGlobalScopes 扫描同一个理由），按租户过滤会把别的租户的孤儿藏起来。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('point_debit_reconciliations', function (Blueprint $table) {
            $table->id();
            // finding_key 是幂等键:同一条差异每天重跑只更新 last_seen_at,
            // 不会把待办刷成一堆重复行,也不会覆盖已写下的人工复核结论。
            $table->string('finding_key', 191)->unique();
            $table->string('classification', 32);            // php_only / gateway_only / points_mismatch / php_blocked
            $table->string('status', 24)->default('open');    // open / reviewed / waived / escalated
            $table->unsignedBigInteger('model_call_log_id')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('external_transaction_id', 191)->nullable();
            $table->string('idempotency_key', 191)->nullable();
            $table->decimal('points_recorded', 14, 4)->nullable();  // GEO 侧记的
            $table->decimal('points_gateway', 14, 4)->nullable();   // 网关账本记的
            $table->json('evidence')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            // 人工复核只写结论,不改账本(见 PointDebitReconciliationService::review)。
            $table->timestamp('reviewed_at')->nullable();
            $table->string('reviewed_by', 120)->nullable();
            $table->string('review_decision', 32)->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_seen_at'], 'pdr_status_seen');
            $table->index('external_transaction_id', 'pdr_ext_tx');
            $table->index('model_call_log_id', 'pdr_log');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('point_debit_reconciliations');
    }
};
