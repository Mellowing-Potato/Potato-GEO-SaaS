<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 敏感词命中日志（执行书 T7）。
 *
 * 这张表是平台**唯一的烟雾报警器**，不是给客服查单用的：
 * 政治词不给租户看见、租户不能自行停用、命中提示只给一句固定文案且不开人工通道，
 * 这套组合的必然后果是——误伤发生时租户侧不会有任何声音。
 * 作者只会改一句再试、再被拦、然后放弃，没人来报障。
 * 所以「哪条词命中最多、命中率突然涨了、集中在哪几个租户」只能从这张表看出来。
 *
 * 读写权限是**故意不对称**的（老板 2026-08-30 澄清）：
 *   - 平台侧（运营台 platform:operations）要查得一清二楚，给定文章 ID 必须能答出
 *     「被哪条词拦的、命中在哪个字段、上下文是什么、平台词还是租户自己加的词」
 *   - 租户侧一个字都读不到：没有任何接口、页面、导出能碰这张表
 * 「不接客服」= 不给租户开人工申诉入口，≠ 平台放弃排查能力。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensitive_word_hits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->index();
            $table->unsignedBigInteger('article_id')->nullable()->index();
            $table->string('word', 255);
            $table->string('severity', 16);
            $table->string('origin', 16);             // platform | tenant
            $table->string('surface', 32);            // 命中在哪个字段：title / summary / content
            $table->text('context')->nullable();      // 命中处前后各 30 字，给运营判误伤用
            // 注：trigger 是 SQL 保留字，Laravel 的 Postgres grammar 一律双引号包列名，
            // Eloquent 读写没问题；写裸 SQL 时记得自己加引号。
            $table->string('trigger', 32);            // create | update | manual_publish | distribute
            $table->timestamps();

            // 按词聚合（T8 的 summary 接口，默认命中篇数倒序）走这个索引。
            $table->index(['word', 'created_at']);
            // 90 天清理按 created_at 删，没索引会全表扫。
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensitive_word_hits');
    }
};
