<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // These receipts and claims belong only to the retired private service.
        // dropIfExists also accepts databases where they were already removed.
        Schema::dropIfExists('qinghe_connection_receipts');
        Schema::dropIfExists('qinghe_connection_test_claims');
    }

    public function down(): void
    {
        throw new RuntimeException('Removed connection records require a database backup restore.');
    }
};
