<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_crawler_hits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('article_id')->nullable();
            $table->unsignedBigInteger('publishing_channel_id')->nullable();
            $table->string('bot_name', 80);
            $table->string('bot_family', 20);
            $table->string('path', 1000)->nullable();
            $table->string('ip_address', 64)->default('');
            $table->date('hit_at');
            $table->timestamps();

            $table->index(['tenant_id', 'bot_family', 'hit_at'], 'ach_tenant_family_date');
            $table->index(['tenant_id', 'article_id'], 'ach_tenant_article');
            $table->index(['tenant_id', 'bot_name', 'hit_at'], 'ach_tenant_bot_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_crawler_hits');
    }
};
