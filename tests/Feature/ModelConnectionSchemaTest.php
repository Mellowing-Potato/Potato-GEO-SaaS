<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ModelConnectionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_retired_connection_tables_are_removed_and_repeated_cleanup_is_safe(): void
    {
        // Derive the legacy tables from the independent historical schema,
        // not from the implementation of the cleanup under test.
        $oldFiles = glob(database_path('migrations/2026_09_27_00000[13]_create_*connection*table.php'));
        $this->assertCount(2, $oldFiles);
        $tables = [];
        foreach ($oldFiles as $path) {
            preg_match("/Schema::create\\('([^']+)'/", file_get_contents($path), $match);
            $this->assertNotEmpty($match[1] ?? null);
            $tables[] = $match[1];
            $this->assertFalse(Schema::hasTable($match[1]));
            (require $path)->up();
            $this->assertTrue(Schema::hasTable($match[1]));
        }
        $migration = require database_path('migrations/2026_11_12_000003_remove_gateway_connection_tables.php');
        $migration->up();
        $migration->up();
        foreach ($tables as $table) {
            $this->assertFalse(Schema::hasTable($table));
        }
        $this->assertTrue(Schema::hasTable('tenant_ai_keys'));
        $this->assertTrue(Schema::hasTable('model_call_logs'));
        $this->assertTrue(Schema::hasTable('knowledge_chunks'));
    }
}
