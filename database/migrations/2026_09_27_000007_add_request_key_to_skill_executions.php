<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skill_executions', function (Blueprint $table): void {
            $table->string('client_request_key', 160)->nullable();
            $table->unique(['tenant_id', 'user_id', 'client_request_key'], 'uq_skill_execution_owner_request');
        });
    }

    public function down(): void
    {
        Schema::table('skill_executions', function (Blueprint $table): void {
            $table->dropUnique('uq_skill_execution_owner_request');
            $table->dropColumn('client_request_key');
        });
    }
};
