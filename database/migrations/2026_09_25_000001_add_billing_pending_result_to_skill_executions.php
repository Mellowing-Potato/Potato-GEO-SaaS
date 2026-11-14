<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('skill_executions', function (Blueprint $table): void {
            $table->longText('billing_pending_result')->nullable();
        });
    }

    public function down(): void
    {
        // Application rollback must keep undelivered, already generated work.
        // Drop this column only after every billing_pending row is reconciled.
    }
};
