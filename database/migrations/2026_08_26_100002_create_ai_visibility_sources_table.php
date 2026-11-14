<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI 可见性监测 — 信源表（批次二 · 单 3）。
 *
 * 字段与上游 GEOFlow AiVisibilitySourceData 14 字段 1:1（计划书 §3.3）：
 * 6 个直接映射（title/url/site_name/summary/publish_time→published_at + sourceType 固定值）、
 * 4 个可派生（citationKey/rank 用 annotations 下标，domain 从 url 解析，snippet 截 summary 前 200 字）、
 * 4 个本轮留空（rankScore/authorityLevel 留 null，contentExcerpt 同 summary 或空，metadata 收 logo_url 等）。
 *
 * ⚠️ logo_url 是带 x-expires 的临时签名链接，**没有永久列**，只进 metadata。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_visibility_sources', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('run_id');
            $table->string('source_type', 50)->default('url_citation');
            $table->string('citation_key', 20)->nullable();
            $table->string('title', 1000)->nullable();
            $table->string('url', 1500);
            $table->string('domain', 255)->nullable();
            $table->string('site_name', 255)->nullable();
            $table->string('snippet', 500)->nullable();
            $table->text('summary')->nullable();
            $table->text('content_excerpt')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->unsignedInteger('rank')->nullable();
            $table->decimal('rank_score', 8, 4)->nullable();
            $table->string('authority_level', 50)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'run_id'], 'avs_tenant_run');
            $table->index(['tenant_id', 'domain'], 'avs_tenant_domain');
            $table->index(['tenant_id', 'site_name'], 'avs_tenant_site');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_visibility_sources');
    }
};
