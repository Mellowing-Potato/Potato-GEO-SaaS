<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('api_idempotency_keys', function (Blueprint $table) {
            $table->timestamp('client_seen_at')->nullable();
            $table->index(['tenant_id', 'user_id', 'route_key', 'client_seen_at'], 'idx_idem_owner_unseen');
        });
    }

    public function down(): void
    {
        Schema::table('api_idempotency_keys', function (Blueprint $table) {
            $table->dropIndex('idx_idem_owner_unseen');
            $table->dropColumn('client_seen_at');
        });
    }
};
