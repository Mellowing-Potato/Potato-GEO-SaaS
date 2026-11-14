<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('publishing_channels', function (Blueprint $table) {
            $table->unsignedInteger('consecutive_failures')->default(0)->after('last_error_message');
        });
    }

    public function down(): void
    {
        Schema::table('publishing_channels', function (Blueprint $table) {
            $table->dropColumn('consecutive_failures');
        });
    }
};
