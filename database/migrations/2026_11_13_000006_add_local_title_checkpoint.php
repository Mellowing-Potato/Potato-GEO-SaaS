<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_idempotency_keys', function (Blueprint $table): void {
            $table->text('model_checkpoint')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('api_idempotency_keys', fn (Blueprint $table) => $table->dropColumn('model_checkpoint'));
    }
};
