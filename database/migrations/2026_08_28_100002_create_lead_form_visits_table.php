<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 线索表单公开页访问采集（单 8，对应阅读页 AnalyticsIngestionService 的 visit 表）。
 *
 * LeadFormVisitMiddleware 照抄 ReadingPageVisitMiddleware：terminable 记真实 status_code
 * （含 404），tenant 由 slug 反查，采集是旁路、异常一律吞掉。本表是它的落点。
 * lead_form_id 在 404（表单不存在/未发布）时为 null —— 爬虫撞死链是有效信号。
 *
 * 生产 Postgres 16：索引名显式命名防 63 字节截断；CI sqlite 也要能跑。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lead_form_visits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('lead_form_id')->nullable()->comment('404 时为 null');
            $table->string('ip_address', 45)->default('');
            $table->text('user_agent')->nullable();
            $table->string('referer', 500)->nullable();
            $table->string('path', 500)->nullable();
            $table->string('method', 10)->nullable();
            $table->unsignedSmallInteger('status_code')->default(200);
            $table->timestamps();

            $table->index(['tenant_id', 'lead_form_id', 'created_at'], 'lead_form_visits_tenant_form_created_idx');
            $table->index(['tenant_id', 'created_at'], 'lead_form_visits_tenant_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_form_visits');
    }
};
