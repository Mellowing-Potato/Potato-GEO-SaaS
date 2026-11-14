<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 单 9 · worker 心跳表（平台运维数据，非租户数据）。
     *
     * ⚠️ Postgres 校验（生产 pg16）：不使用 ->after() 这类 MySQL 写法。
     * json 列用 ->json()（Laravel 在 PG 落 json、在 sqlite 落 text），跨驱动可跑。
     */
    public function up(): void
    {
        Schema::create('worker_heartbeats', function (Blueprint $table) {
            $table->string('worker_id')->primary();
            $table->string('status')->default('running');
            $table->timestamp('last_seen_at')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('worker_heartbeats');
    }
};
