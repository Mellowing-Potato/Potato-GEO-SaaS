<?php

namespace Tests\Support;

use App\Support\TenantContext;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;

trait UsesFileSqlite
{
    protected string $databaseFile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->databaseFile = tempnam(sys_get_temp_dir(), 'yanji-sqlite-');
        config([
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => $this->databaseFile,
            'database.connections.sqlite.journal_mode' => 'WAL',
        ]);
        DB::purge('sqlite');
        Artisan::call('migrate', ['--force' => true]);
    }

    protected function tearDown(): void
    {
        TenantContext::forget();
        DB::purge('sqlite');
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (isset($this->databaseFile) && is_file($this->databaseFile.$suffix)) {
                unlink($this->databaseFile.$suffix);
            }
        }
        parent::tearDown();
    }
}
