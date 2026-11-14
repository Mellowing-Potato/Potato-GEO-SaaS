<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('manual_publications', function (Blueprint $table) {
            $table->mediumText('content')->change();
            $table->text('draft_url')->nullable();
            $table->timestamp('draft_synced_at')->nullable();
            $table->char('draft_content_fingerprint', 64)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('manual_publications', function (Blueprint $table) {
            $table->dropColumn(['draft_url', 'draft_synced_at', 'draft_content_fingerprint']);
            $table->text('content')->change();
        });
    }
};
