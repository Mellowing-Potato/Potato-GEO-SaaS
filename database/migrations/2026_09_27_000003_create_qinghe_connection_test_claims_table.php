<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('qinghe_connection_test_claims', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('request_key', 160);
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id'], 'uq_connection_claim_owner');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('qinghe_connection_test_claims');
    }
};
