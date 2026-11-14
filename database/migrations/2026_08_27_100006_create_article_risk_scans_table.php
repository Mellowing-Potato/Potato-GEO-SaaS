<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 文章风险扫描结果落库（ArticleRiskScan）。
 *
 * 上游 ArticleRiskScanner::record()/isFresh() 需要此表。单 7 复刻 ArticleRiskScanner「整个迁」（§二），
 * 它是 record/isFresh 的硬依赖，故一并迁过来（上游文件清单未单独列出，此处补上以保编译与功能完整）。
 *
 * 外键统一指向 users（§一-3）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('article_risk_scans', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('article_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('trigger', 32);
            $table->string('content_hash', 64);
            $table->string('dictionary_hash', 64);
            $table->string('status', 16);
            $table->unsignedInteger('match_count')->default(0);
            $table->json('matches')->nullable();
            $table->timestamp('scanned_at')->nullable();

            $table->index(['tenant_id', 'article_id'], 'ars_tenant_article');
            $table->index(['tenant_id', 'content_hash'], 'ars_tenant_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('article_risk_scans');
    }
};
