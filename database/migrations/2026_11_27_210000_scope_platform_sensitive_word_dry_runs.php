<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('platform_sensitive_word_dry_runs', function (Blueprint $table) {
            // Do not attribute historical cross-workspace results to their requester's workspace.
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('platform_sensitive_word_dry_runs', function (Blueprint $table) {
            $table->dropIndex(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
