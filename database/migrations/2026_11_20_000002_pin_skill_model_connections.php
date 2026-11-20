<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skill_executions', function (Blueprint $table) {
            $table->json('model_connection_snapshot')->nullable();
        });
        Schema::table('model_call_logs', function (Blueprint $table) {
            $table->string('model', 512)->change();
        });
    }

    public function down(): void
    {
        throw new RuntimeException('模型调用记录可能含有较长的型号，请从升级前的数据库备份恢复。');
    }
};
