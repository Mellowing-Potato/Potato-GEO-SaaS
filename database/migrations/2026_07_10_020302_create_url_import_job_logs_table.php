<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 阶段 1.3 URL 文章导入加工：导入任务步骤/失败日志（对标 GEOFlow UrlImportJobLog）。
     *
     * 每次抓取/转换/落库的关键步骤与失败原因都写入此表，供前端追溯（非阻塞）。
     */
    public function up(): void
    {
        Schema::create('url_import_job_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('url_import_id')->constrained('url_imports')->cascadeOnDelete();
            $table->string('level', 10)->default('info'); // info|error
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'url_import_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('url_import_job_logs');
    }
};
