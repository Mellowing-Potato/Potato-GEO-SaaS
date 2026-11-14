<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analytics_visit_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('project_id')->nullable();
            $table->unsignedBigInteger('article_id')->nullable();
            $table->unsignedBigInteger('publishing_channel_id')->nullable();
            $table->string('ip_address', 64)->default('');
            $table->string('user_agent', 1000)->default('');
            $table->string('bot_name', 80)->nullable();
            $table->string('bot_family', 20)->nullable();
            $table->string('referer', 1500)->nullable();
            $table->string('path', 1000)->nullable();
            $table->string('method', 10)->default('GET');
            $table->unsignedInteger('status_code')->nullable();
            $table->string('traffic_type', 20)->default('unknown');
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'created_at'], 'avl_tenant_created');
            $table->index(['tenant_id', 'traffic_type'], 'avl_tenant_traffic');
            $table->index(['tenant_id', 'article_id'], 'avl_tenant_article');
            $table->index(['tenant_id', 'publishing_channel_id'], 'avl_tenant_channel');
            $table->index(['tenant_id', 'project_id'], 'avl_tenant_project');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analytics_visit_logs');
    }
};
