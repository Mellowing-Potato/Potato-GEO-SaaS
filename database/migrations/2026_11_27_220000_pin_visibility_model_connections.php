<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_visibility_runs', function (Blueprint $table) {
            // Historical pending runs have no verifiable queued identity.
            // Keep them null; existing supplier receipts remain recoverable.
            $table->json('model_connection_snapshot')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('ai_visibility_runs', function (Blueprint $table) {
            $table->dropColumn('model_connection_snapshot');
        });
    }
};
