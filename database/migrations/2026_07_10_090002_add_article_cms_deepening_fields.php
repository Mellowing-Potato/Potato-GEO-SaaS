<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            // 结构化分类（对标 GEOFlow Article.category_id → Category）
            $table->foreignId('category_id')
                ->nullable()
                ->constrained('categories')
                ->nullOnDelete();

            // 署名作者（对标 GEOFlow Article.author_id → Author 素材库署名）
            // 注：geo-saas 的 article.author_id 指向 User（实际撰写人），
            // byline_author_id 指向素材库 Author（对外署名笔名）。
            $table->foreignId('byline_author_id')
                ->nullable()
                ->constrained('authors')
                ->nullOnDelete();

            // OG / 社交分享 SEO 字段
            $table->string('og_title', 255)->nullable();
            $table->text('og_description')->nullable();
            $table->string('og_image', 1000)->nullable();

            $table->index(['tenant_id', 'category_id']);
        });
    }

    public function down(): void
    {
        Schema::table('articles', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'category_id']);
            $table->dropConstrainedForeignId('category_id');
            $table->dropConstrainedForeignId('byline_author_id');
            $table->dropColumn(['og_title', 'og_description', 'og_image']);
        });
    }
};
