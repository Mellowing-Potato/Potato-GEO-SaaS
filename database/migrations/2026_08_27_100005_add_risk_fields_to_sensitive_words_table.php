<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 为 ArticleRiskScanner（单 7 复刻，上游 §一-1）补全 sensitive_words 字段。
 *
 * 上线前 geo-saas 的 SensitiveWord 只有 word + tenant_id；上游扫描器需要从同一张表读
 * severity / category / is_enabled / suggestion / applies_to，并依赖 ruleCacheVersion()
 * 控制缓存失效。本迁移补齐字段，模型层补静态方法（见 app/Models/SensitiveWord.php）。
 *
 * 不用 ->after()（PG 无效）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sensitive_words', function (Blueprint $table) {
            $table->string('severity', 16)->default('warning');
            $table->string('category', 32)->default('sensitive');
            $table->boolean('is_enabled')->default(true);
            $table->text('suggestion')->nullable();
            $table->json('applies_to')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('sensitive_words', function (Blueprint $table) {
            $table->dropColumn(['severity', 'category', 'is_enabled', 'suggestion', 'applies_to']);
        });
    }
};
