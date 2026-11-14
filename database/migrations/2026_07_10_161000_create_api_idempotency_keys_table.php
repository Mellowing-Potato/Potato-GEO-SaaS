<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 对外 API 写接口幂等缓存表（复刻 GEOFlow api_idempotency_keys，增加租户隔离）。
     *
     * 用途：客户端在 POST/PATCH 请求携带 X-Idempotency-Key，
     * 同一 (key, route, tenant, user) 的重复请求直接回放首次响应，
     * 避免网络重试导致重复创建（如重复下单、重复发布）。
     */
    public function up(): void
    {
        Schema::create('api_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->string('idempotency_key', 255);
            $table->string('route_key', 255);
            $table->text('request_hash');
            $table->longText('response_body')->nullable();
            $table->unsignedInteger('response_status')->nullable();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();

            $table->unique(['idempotency_key', 'route_key', 'tenant_id', 'user_id'], 'uq_idem_key_route_tenant_user');
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_idempotency_keys');
    }
};
