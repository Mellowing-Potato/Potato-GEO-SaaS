<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('skill_execution_recovery_audits', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('skill_execution_id');
            $table->string('action', 100);
            $table->json('details');
            $table->timestamp('created_at');
            $table->index(['tenant_id', 'skill_execution_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('skill_execution_recovery_audits');
    }
};
