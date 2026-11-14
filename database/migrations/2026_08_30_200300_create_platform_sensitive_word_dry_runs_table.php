<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 平台敏感词「加词前试算」结果表（执行书 T8）。
 *
 * 这张表是本批的**安全阀**，不是可选的便利功能。理由链条：
 *   1. 平台词一律 blocked，命中即挡住发布；
 *   2. 租户**不能**自助停用平台词（政治词尤其不能）；
 *   3. 命中提示只给一句固定文案、不点名、不开人工申诉通道（T10）。
 * 三条叠起来的后果是：**误伤的代价 100% 由平台承担，且租户侧不会报障。**
 * 所以运营在按下「保存」之前，必须先看到「这批词会让多少篇现有稿子发不出去、
 * 集中在哪几个租户」。没有这一步，一次手滑（比如误加了「发展」这种常用词）
 * 就能让全平台客户同时卡住，而平台要等到看聚合表才发现。
 *
 * ⚠️ 为什么落表而不塞缓存：
 * 试算是跨租户全表扫描，必须丢给队列跑（HTTP 里同步扫会超时，且会拖垮
 * 运营台）。异步 = 结果要能被后续请求捞回来。缓存被清就等于结果消失，
 * 运营只能重扫一遍全表；而这张表同时也是「谁在什么时候试算过哪批词」的
 * 事实记录，加错词之后复盘要用。
 *
 * candidates 里存的是**候选词明文**。这张表与 platform_sensitive_words 同级敏感，
 * 只有 platform:operations 门后能读，租户侧任何接口都不碰。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_sensitive_word_dry_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('requested_by')->nullable();

            // 候选词明文数组。不做 hash：复盘时要看清「当时试算的到底是哪几个词」。
            $table->json('candidates');

            // pending → running → completed / failed
            $table->string('status', 16)->default('pending')->index();

            $table->unsignedInteger('articles_scanned')->default(0);
            $table->unsignedInteger('articles_skipped')->default(0);
            $table->unsignedInteger('hit_articles')->default(0);

            // 按租户分组的命中篇数：[{tenant_id, tenant_name, hit_articles}]
            $table->json('by_tenant')->nullable();

            // 按词分组：[{word, hit_articles, hit_tenants, samples: [最多 3 条上下文]}]
            $table->json('by_word')->nullable();

            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_sensitive_word_dry_runs');
    }
};
