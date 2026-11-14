<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 阶段 1.3 URL 文章导入加工：导入任务表（对标 GEOFlow UrlImportJob）。
     *
     * 一条记录代表一次「提交 URL → 抓取 → 转 Markdown → 生成草稿」的任务。
     * 状态：pending → processing → completed / failed。完成后关联生成的 Article 草稿。
     */
    public function up(): void
    {
        Schema::create('url_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2000);
            $table->string('title', 500)->nullable();
            $table->string('status', 20)->default('pending'); // pending|processing|completed|failed
            $table->foreignId('article_id')->nullable()->constrained('articles')->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('url_imports');
    }
};
