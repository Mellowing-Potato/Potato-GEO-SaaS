<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenant_ai_keys', function (Blueprint $table) {
            // Historical credentials remain unassigned; they are never used as fallback keys.
            $table->string('slot', 20)->nullable();
            $table->dropUnique(['tenant_id', 'provider']);
            $table->unique(['tenant_id', 'slot']);
        });
    }

    public function down(): void
    {
        // Multiple slots may now use the same provider. A rollback must not discard keys.
        throw new RuntimeException('请从升级前的数据库备份恢复。');
    }
};
