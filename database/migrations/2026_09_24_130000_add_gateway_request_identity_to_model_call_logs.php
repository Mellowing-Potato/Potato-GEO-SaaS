<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('model_call_logs', function (Blueprint $table) {
            $table->string('gateway_request_id', 160)->nullable()->unique();
        });
    }

    public function down(): void
    {
        Schema::table('model_call_logs', function (Blueprint $table) {
            $table->dropUnique(['gateway_request_id']);
            $table->dropColumn('gateway_request_id');
        });
    }
};
