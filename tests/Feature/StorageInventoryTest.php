<?php

namespace Tests\Feature;

use App\Services\Storage\StorageInventoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class StorageInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_read_only_scan_counts_confirmed_files_and_keeps_ambiguous_ownership_separate(): void
    {
        $originalStorage = storage_path();
        $temporary = sys_get_temp_dir().'/geo-storage-inventory-'.bin2hex(random_bytes(8));
        app()->useStoragePath($temporary);
        try {
            $tenantA = DB::table('tenants')->insertGetId(['subdomain' => 'inventory-a', 'name' => 'Synthetic A']);
            $tenantB = DB::table('tenants')->insertGetId(['subdomain' => 'inventory-b', 'name' => 'Synthetic B']);
            $baseA = DB::table('knowledge_bases')->insertGetId(['tenant_id' => $tenantA, 'name' => 'A']);
            $baseB = DB::table('knowledge_bases')->insertGetId(['tenant_id' => $tenantB, 'name' => 'B']);
            $documentIds = [];
            foreach ([
                [$tenantA, $baseA, 'knowledge/a/owned.txt'],
                [$tenantA, $baseA, 'knowledge/shared.txt'],
                [$tenantB, $baseB, 'knowledge/shared.txt'],
                [$tenantA, $baseA, 'knowledge/a/missing.txt'],
            ] as [$tenant, $base, $path]) {
                $documentIds[] = DB::table('knowledge_documents')->insertGetId([
                    'tenant_id' => $tenant, 'knowledge_base_id' => $base,
                    'title' => 'Synthetic', 'source_type' => 'upload', 'file_path' => $path,
                ]);
            }
            DB::table('knowledge_chunks')->insert([
                'tenant_id' => $tenantA, 'document_id' => $documentIds[0],
                'chunk_index' => 0, 'content' => 'hello',
            ]);
            File::ensureDirectoryExists(storage_path('app/private/knowledge/a'));
            File::put(storage_path('app/private/knowledge/a/owned.txt'), 'owned');
            File::put(storage_path('app/private/knowledge/shared.txt'), 'ambiguous');
            File::put(storage_path('app/private/knowledge/unreferenced.txt'), 'orphan');
            $beforeRows = DB::table('knowledge_documents')->count();
            $beforeHash = hash_file('sha256', storage_path('app/private/knowledge/a/owned.txt'));

            $report = app(StorageInventoryService::class)->inspect();

            $this->assertSame(['files' => 1, 'bytes' => 5], $report['tenant_files'][(string) $tenantA]['knowledge']);
            $this->assertArrayNotHasKey((string) $tenantB, $report['tenant_files']);
            $this->assertSame(2, $report['unowned']['files']);
            $this->assertSame(15, $report['unowned']['bytes']);
            $this->assertSame(1, $report['missing_referenced_files']['knowledge']);
            $this->assertSame(5, $report['tenant_content'][(string) $tenantA]['knowledge_chunks']);
            $this->assertStringNotContainsString('owned.txt', json_encode($report));
            $this->assertSame($beforeRows, DB::table('knowledge_documents')->count());
            $this->assertSame($beforeHash, hash_file('sha256', storage_path('app/private/knowledge/a/owned.txt')));
        } finally {
            app()->useStoragePath($originalStorage);
            File::deleteDirectory($temporary);
        }
    }
}
