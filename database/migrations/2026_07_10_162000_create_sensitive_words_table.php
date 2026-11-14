<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 敏感词实体表（复刻 GEOFlow sensitive_words，增加租户隔离）。
     *
     * 用于内容合规：文章标题/正文命中任一敏感词时标记 flagged_sensitive，
     * 由 SensitiveWordChecker 在文章创建/更新时扫描。
     */
    public function up(): void
    {
        Schema::create('sensitive_words', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('word', 255);
            $table->timestamps();

            $table->unique(['tenant_id', 'word'], 'uq_sensitive_word_tenant');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensitive_words');
    }
};
