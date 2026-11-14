<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // reconciliation_required is longer than the original varchar(20).
        // Text keeps future recovery states from silently exceeding a new fixed limit.
        Schema::table('ai_visibility_runs', function (Blueprint $table): void {
            $table->text('status')->default('pending')->change();
        });
    }

    public function down(): void
    {
        // Keep the text column on rollback: existing recovery rows may exceed 20 chars.
    }
};
