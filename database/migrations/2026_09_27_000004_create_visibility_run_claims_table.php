<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('visibility_run_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->char('request_hash', 64);
            $table->string('request_key', 160);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'request_hash'], 'uq_visibility_claim_owner_body');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('visibility_run_claims');
    }
};
