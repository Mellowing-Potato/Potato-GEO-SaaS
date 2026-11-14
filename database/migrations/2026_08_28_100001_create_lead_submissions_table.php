<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 线索提交记录（单 8）。
 *
 * 多租户改造要点（见执行书 §二-1 / §二-2）：
 *  - 带 tenant_id（否则公开提交落库时若上下文丢失会写错租户）。
 *  - 外键 handled_by 指向上游的 admins 表在本仓不存在 —— 改成 users（§二-2）。
 *  - lead_form_id 外键指向 lead_forms，nullOnDelete（删表单后提交记录保留、form 置空）。
 *    注意这是 DB 层兜底，不是应用层的删除路径：LeadFormController::destroy 对「已有提交的
 *    表单」直接 422 拒绝，应用层根本走不到这条级联。nullOnDelete 保的是别的路径
 *    （租户注销级联、人工 SQL）—— 那时线索仍留库，只把 lead_form_id 置空。两层都要在。
 *  - 生产 Postgres 16：不用 ->after()；索引名显式命名防 63 字节截断。
 *  - CI sqlite 也要能跑。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_submissions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->foreignId('lead_form_id')->nullable()->constrained('lead_forms')->nullOnDelete();
            $table->string('status', 20)->default('new')->comment('new/contacted/qualified/invalid/converted');
            $table->json('payload')->nullable()->comment('提交字段 JSON（仅存表单声明的字段）');
            $table->string('source_url', 500)->nullable()->comment('来源页面');
            $table->string('ip_address', 45)->default('')->comment('提交 IP');
            $table->text('user_agent')->nullable()->comment('User-Agent');
            $table->text('note')->nullable()->comment('后台备注');
            // §二-2：本仓没有 admins 表，外键指向 users。
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable()->comment('最近处理时间');
            $table->timestamps();

            $table->index(['tenant_id', 'created_at'], 'lead_submissions_tenant_created_idx');
            $table->index(['tenant_id', 'status'], 'lead_submissions_tenant_status_idx');
            $table->index(['lead_form_id', 'created_at'], 'lead_submissions_form_created_idx');
            $table->index(['status', 'created_at'], 'lead_submissions_status_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_submissions');
    }
};
