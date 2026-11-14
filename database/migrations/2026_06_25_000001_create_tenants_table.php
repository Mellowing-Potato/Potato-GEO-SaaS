<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->id();
            $table->string('subdomain', 63)->unique()->comment('子域名前缀');
            $table->string('name')->comment('租户名称');
            $table->string('plan', 30)->default('basic')->comment('订阅计划: basic/pro/enterprise');
            $table->json('settings')->nullable()->comment('租户配置（品牌色、Logo URL 等）');
            $table->string('status', 20)->default('active')->comment('状态: active/suspended/trialing');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
