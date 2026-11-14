<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skill_executions', function (Blueprint $table) {
            $table->string('execution_mode', 20)
                ->nullable()
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('skill_executions', function (Blueprint $table) {
            $table->dropColumn('execution_mode');
        });
    }
};
