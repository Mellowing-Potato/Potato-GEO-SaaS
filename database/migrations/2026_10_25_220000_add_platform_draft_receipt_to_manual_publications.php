<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_publications', function (Blueprint $table) {
            $table->json('draft_receipt')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('manual_publications', function (Blueprint $table) {
            $table->dropColumn('draft_receipt');
        });
    }
};
