<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Child tables precede their parents. Shared usage records and columns remain.
        foreach ([
            'geo_invoice_requests',
            'geo_commercial_events',
            'geo_tenant_subscriptions',
            'geo_commercial_orders',
            'point_debit_reconciliations',
            'point_unit_legacy_records',
            'point_unit_state',
        ] as $table) {
            Schema::dropIfExists($table);
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Removed billing data requires a database backup restore.');
    }
};
