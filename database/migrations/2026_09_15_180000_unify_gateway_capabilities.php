<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('ai_visibility_runs', fn (Blueprint $table) => $table->decimal('upstream_cost_fen', 10, 2)->nullable()->default(null)->change());
        Schema::table('skill_executions', fn (Blueprint $table) => $table->json('gateway_requests')->nullable());
        // Preserve encrypted credentials and historical usage. Only gateway bindings migrate.
        DB::table('tenant_ai_keys')->where('provider', 'potato')->orderBy('id')->each(function ($row) {
            $metadata = json_decode($row->metadata ?? '{}', true) ?: [];
            $metadata['allowed_models'] = ['geo-text', 'geo-vision', 'geo-embed'];
            if (DB::table('tenant_ai_keys')->where('tenant_id', $row->tenant_id)->where('provider', 'gateway')->exists()) {
                DB::table('tenant_ai_keys')->where('id', $row->id)->update(['is_active' => false]);
                return;
            }
            DB::table('tenant_ai_keys')->where('id', $row->id)->update([
                'provider' => 'gateway', 'metadata' => json_encode($metadata, JSON_UNESCAPED_UNICODE),
            ]);
        });
        DB::table('tenant_ai_keys')->whereNotIn('provider', ['gateway', 'potato'])->update(['is_active' => false]);
    }

    public function down(): void
    {
        // Historical logs and supplier credentials must never be reconstructed by guessing.
    }
};
