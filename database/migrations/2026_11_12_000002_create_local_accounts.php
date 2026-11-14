<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('local_browser_sessions', function (Blueprint $table) {
            $table->string('fingerprint', 64)->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('created_at');
            $table->timestamp('expires_at')->index();
        });
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });
        Schema::create('instance_settings', function (Blueprint $table) {
            $table->unsignedInteger('id')->primary();
            $table->boolean('registration_enabled')->default(false);
        });
        DB::table('instance_settings')->insert(['id' => 1, 'registration_enabled' => false]);
    }

    public function down(): void
    {
        Schema::dropIfExists('local_browser_sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('instance_settings');
    }
};
