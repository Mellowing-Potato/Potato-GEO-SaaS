<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 平台级敏感词基线库（执行书 T3）。
 *
 * 为什么单独一张表、不复制进各租户的 sensitive_words：
 *   1. 政治词不能出现在租户可见界面——租户敏感词页读的是 sensitive_words，
 *      平台词落在另一张表里就是天然不可见，不需要写过滤逻辑去藏（写过滤必有漏）；
 *   2. 平台要随时能更新——一行改动全平台生效，不用给 N 个租户重灌，也不用重新部署；
 *   3. 不设条数上限——不再是 N 租户 × M 词一起撞扫描器上限。
 *
 * 没有 tenant_id 列，也不许挂 TenantScope（见 App\Models\PlatformSensitiveWord）。
 * 时间戳从 2026_08_30_2000xx 起编：2026_08_30_100000 已被线索通知批次占用。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_sensitive_words', function (Blueprint $table) {
            $table->id();
            // 全平台唯一，没有 tenant 维度
            $table->string('word', 255)->unique();
            $table->string('severity', 16)->default('blocked');
            // politics | illegal
            $table->string('category', 32)->default('politics');
            $table->boolean('is_enabled')->default(true);
            // 仅运营台可见，不发给作者（T10：平台词命中不点名）
            $table->text('suggestion')->nullable();
            // 为什么收这条词、词源；灌入批次标记也写在这里
            $table->text('note')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->index(['is_enabled', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_sensitive_words');
    }
};
