<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admin_activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('actor_id')->nullable();
            $table->string('actor_type', 40)->default('user');
            $table->string('actor_name', 120)->nullable();
            $table->string('action', 80);
            $table->string('request_method', 10)->default('GET');
            $table->string('page', 120)->nullable();
            $table->string('target_type', 60)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('ip_address', 64)->default('');
            $table->text('details')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'created_at'], 'aalog_tenant_created');
            $table->index(['actor_id', 'created_at'], 'aalog_actor_created');
            $table->index('action', 'aalog_action');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_activity_logs');
    }
};
