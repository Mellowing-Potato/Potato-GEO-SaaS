<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // 标题库
        Schema::create('title_libraries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('title', 500);
            $table->text('note')->nullable();
            $table->string('category', 120)->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'category']);
        });

        // 关键词库
        Schema::create('keyword_libraries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('keyword', 255);
            $table->text('note')->nullable();
            $table->string('category', 120)->nullable();
            $table->unsignedInteger('search_volume')->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'category']);
        });

        // 图片库
        Schema::create('image_libraries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('url', 2000);
            $table->string('title', 255)->nullable();
            $table->string('alt', 255)->nullable();
            $table->string('source', 120)->nullable();
            $table->string('storage', 60)->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();

            $table->index(['tenant_id']);
        });

        // 作者库
        Schema::create('authors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
            $table->string('name', 255);
            $table->text('bio')->nullable();
            $table->string('avatar_url', 2000)->nullable();
            $table->string('external_link', 2000)->nullable();
            $table->unsignedInteger('usage_count')->default(0);
            $table->timestamps();

            $table->index(['tenant_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('authors');
        Schema::dropIfExists('image_libraries');
        Schema::dropIfExists('keyword_libraries');
        Schema::dropIfExists('title_libraries');
    }
};
