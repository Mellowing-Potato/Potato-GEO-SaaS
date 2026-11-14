<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 线索表单（单 8 · 公开线索收集）。
 *
 * 多租户改造要点（见执行书 §二-1）：
 *  - slug 必须是「租户内唯一」，不能照抄上游的全局 unique()：上游是单租户系统，
 *    照抄会导致 A 租户占了 contact 后 B 租户永远建不了 contact，且建表报错会泄露
 *    别的租户占用了哪些标识。这里用复合唯一 [tenant_id, slug]。
 *  - 两张表都带 tenant_id（公开表单页没有登录/租户上下文，靠 LeadFormDeliveryService
 *    显式 where tenant_id 定位，绝不依赖全局作用域 fail-open）。
 *  - 生产 Postgres 16：不用 ->after()；索引名 63 字节上限，超长的显式命名。
 *  - CI sqlite 也要能跑。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_forms', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 120)->comment('表单名称');
            $table->string('slug', 120)->comment('前台访问标识（租户内唯一）');
            $table->string('status', 20)->default('active')->comment('active/inactive');
            $table->text('description')->nullable()->comment('表单说明');
            $table->string('submit_button_label', 80)->default('提交')->comment('提交按钮文案');
            $table->text('success_message')->nullable()->comment('提交成功文案');
            $table->json('fields')->nullable()->comment('字段配置 JSON');
            $table->timestamps();

            // 租户内唯一：A 租户占用的 slug 不影响 B 租户（§二-1）。
            $table->unique(['tenant_id', 'slug'], 'lead_forms_tenant_slug_unique');
            $table->index(['tenant_id', 'status'], 'lead_forms_tenant_status_idx');
            // 公开页按 tenantSlug + formSlug 两段定位，索引名在 63 字节内。
            $table->index(['tenant_id', 'slug'], 'lead_forms_tenant_slug_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_forms');
    }
};
