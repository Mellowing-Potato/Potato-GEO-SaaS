<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 文章「未分发原因」字段（执行书 T10 的作者可见落点）。
 *
 * 为什么必须有这一列：T6 的出站闸门是后台钩子触发的
 * （Article.php 的 created / updated 钩子 → triggerAutoDistribution），
 * 被拦的时候作者根本不在界面上。如果只写进 distribution_logs，
 * 作者看到的就是「稿子发出去了但站点上没有」——他不知道要改稿，也无从改起，
 * 这条闸门对客户就是**静默失败**。
 *
 * 存的内容只有那一句固定文案（ArticleRiskScanner::PLATFORM_BLOCK_NOTICE），
 * **绝不存命中的平台词**：这一列会经 /api/v1/articles 回给作者，
 * 存了词就等于把红线清单一条一条公开出去（老板第 5 条）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->string('distribution_blocked_reason', 255)
                ->nullable()
                ->after('flagged_sensitive');
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropColumn('distribution_blocked_reason');
        });
    }
};
