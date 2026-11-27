<?php

namespace Tests\Postgres;

use App\Models\{KnowledgeBase, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\{DB, File, Http, Storage};
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;
use Tests\Support\{KnowledgeProjectFixtures, UsesWorkspaceModels};
use Tests\TestCase;

/** Real PostgreSQL FK key-share lock versus the deletion's FOR UPDATE barrier. */
class KnowledgeDeletionTransactionTest extends TestCase
{
    use DatabaseMigrations, KnowledgeProjectFixtures, UsesWorkspaceModels;

    private ?Process $writer = null;
    private ?string $directory = null;

    // Existing migrations intentionally forbid rollback. The next case migrates fresh.
    public function runDatabaseMigrations(): void
    {
        $this->beforeRefreshingDatabase();
        $this->refreshTestDatabase();
        $this->afterRefreshingDatabase();
    }

    protected function beforeRefreshingDatabase(): void
    {
        $this->assertTrue(app()->environment('testing'));
        $this->assertFileDoesNotExist(base_path('.env'));
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('postgres', config('database.connections.pgsql.host'));
        $this->assertSame('geo_test', config('database.connections.pgsql.database'));
        $this->assertSame('geo_test', config('database.connections.pgsql.username'));
        $this->assertEmpty(config('database.connections.pgsql.url'));
    }

    protected function tearDown(): void
    {
        try {
            if ($this->writer?->isRunning()) {
                $this->writer->stop(1);
            }
            while (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            if ($this->directory !== null) {
                File::deleteDirectory($this->directory);
            }
            TenantContext::forget();
            parent::tearDown();
        } finally {
            RefreshDatabaseState::$migrated = false;
            RefreshDatabaseState::$lazilyRefreshed = false;
        }
    }

    public function test_delete_waits_for_uncommitted_stage_text_before_enumerating_materials(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        config(['app.tenant_domain' => 'geo.example.test']);
        $tenant = Tenant::create(['name' => 'PG deletion fixture', 'subdomain' => 'pg-delete', 'status' => 'active']);
        TenantContext::set($tenant);
        $this->configureWorkspaceModelSlots($tenant);
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $base = KnowledgeBase::create(['tenant_id' => $tenant->id,
            'project_id' => self::knowledgeProjectId($tenant->id), 'name' => 'Pending material fixture', 'status' => 'active']);
        Sanctum::actingAs($user, ['*']);
        $this->withHeader('X-Tenant', $tenant->subdomain)->withHeader('X-Project-Id', (string) $base->project_id);
        $this->assertSame(0, DB::transactionLevel(), 'The child must see committed fixtures, not a RefreshDatabase transaction.');
        $parentBackend = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
        DB::statement("SET lock_timeout = '15s'");

        $this->directory = storage_path('framework/testing/knowledge-delete-pg-'.Str::uuid());
        File::makeDirectory($this->directory, 0700, true);
        $this->writer = new Process([
            PHP_BINARY, '-r', $this->writerScript(), $this->directory, Storage::disk('local')->path(''),
            (string) $tenant->id, (string) $base->id, (string) $parentBackend,
        ], base_path(), [
            'APP_ENV' => 'testing', 'AI_MOCK_MODE' => 'true', 'APP_KEY' => config('app.key'),
            'DB_CONNECTION' => 'pgsql', 'DB_HOST' => 'postgres', 'DB_PORT' => '5432',
            'DB_DATABASE' => 'geo_test', 'DB_USERNAME' => 'geo_test', 'DB_PASSWORD' => 'geo_test_disposable', 'DB_URL' => '',
            'CACHE_STORE' => 'array', 'QUEUE_CONNECTION' => 'sync', 'SESSION_DRIVER' => 'array',
            'BROADCAST_CONNECTION' => 'null', 'MAIL_MAILER' => 'array',
        ], timeout: 25);
        $this->writer->start();
        $this->awaitReady();
        $registered = json_decode(file_get_contents($this->directory.'/ready.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotSame($parentBackend, $registered['backend_pid']);
        $this->assertSame(0, DB::table('knowledge_documents')->where('knowledge_base_id', $base->id)->count(),
            'stageText wrote its source file, but its enclosing transaction has not committed.');
        $this->assertTrue(Storage::disk('local')->exists($registered['directory'].'/content.md'));

        // The child commits only after PostgreSQL observes this connection
        // waiting for its FK lock. There is no sleep-based guess about query order.
        $this->deleteJson('/api/v1/knowledge-bases/'.$base->id)->assertOk();
        $this->assertSame(0, $this->writer->wait(), $this->writer->getErrorOutput().$this->writer->getOutput());
        $this->assertFalse(Storage::disk('local')->directoryExists($registered['directory']),
            'A document committed during deletion must not leave an orphaned content.md after the FK cascade.');
        $this->assertDatabaseMissing('knowledge_documents', ['id' => $registered['id']]);
        $this->assertDatabaseMissing('knowledge_bases', ['id' => $base->id]);

        $blocked = json_decode(file_get_contents($this->directory.'/blocked.json'), true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame($parentBackend, $blocked['blocked_pid']);
        $this->assertStringContainsString('knowledge_bases', strtolower($blocked['query']));
        $this->assertStringContainsString('for update', strtolower($blocked['query']),
            'The barrier must wait before reading documents; a later DELETE wait is too late.');
        Http::assertNothingSent();
    }

    private function awaitReady(): void
    {
        $deadline = microtime(true) + 12;
        do {
            clearstatcache();
            if (is_file($this->directory.'/ready.json')) {
                return;
            }
            if (! $this->writer->isRunning()) {
                $this->fail('The stageText writer exited before its uncommitted material was ready: '
                    .$this->writer->getErrorOutput().$this->writer->getOutput());
            }
            usleep(10000);
        } while (microtime(true) < $deadline);
        $this->fail('Timed out bootstrapping the isolated PostgreSQL writer.');
    }

    private function writerScript(): string
    {
        return <<<'PHP'
if (getenv('APP_ENV') !== 'testing' || getenv('AI_MOCK_MODE') !== 'true'
    || getenv('DB_HOST') !== 'postgres' || getenv('DB_DATABASE') !== 'geo_test'
    || getenv('DB_USERNAME') !== 'geo_test' || getenv('DB_URL') !== '' || is_file('.env')) {
    fwrite(STDERR, "Refusing a non-disposable PostgreSQL fixture.\n"); exit(2);
}
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (Illuminate\Support\Facades\DB::connection()->getDriverName() !== 'pgsql'
    || Illuminate\Support\Facades\DB::connection()->getDatabaseName() !== 'geo_test') {
    throw new RuntimeException('Unexpected fixture connection.');
}
config(['filesystems.disks.local.root' => $argv[2]]);
Illuminate\Support\Facades\Storage::forgetDisk('local');
Illuminate\Support\Facades\Http::preventStrayRequests();
App\Support\TenantContext::set(App\Models\Tenant::findOrFail((int) $argv[3]));
$write = static function (string $name, array $data) use ($argv): void {
    $path = $argv[1].'/'.$name.'.json';
    file_put_contents($path.'.tmp', json_encode($data, JSON_THROW_ON_ERROR));
    rename($path.'.tmp', $path);
};
Illuminate\Support\Facades\DB::beginTransaction();
try {
    // No explicit parent-row lock here: the real INSERT's foreign key takes
    // KEY SHARE, which is compatible with a plain non-key UPDATE, not FOR UPDATE.
    $service = new App\Services\Knowledge\DocumentIngestionService(new App\Services\AI\MockAiProvider);
    $document = $service->stageText((int) $argv[4], '未提交材料', '真实 PostgreSQL 事务中的待提交材料。');
    $writerBackend = (int) Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    $write('ready', ['id' => $document->id, 'directory' => $document->storageDirectory(), 'backend_pid' => $writerBackend]);
    $deadline = microtime(true) + 12;
    do {
        // This writer is in a long transaction; refresh the statistics snapshot.
        Illuminate\Support\Facades\DB::select('SELECT pg_stat_clear_snapshot()');
        $waiting = Illuminate\Support\Facades\DB::selectOne(
            "SELECT pid, query FROM pg_stat_activity WHERE pid = ? AND wait_event_type = 'Lock'
                AND CAST(? AS integer) = ANY(pg_blocking_pids(pid))",
            [(int) $argv[5], $writerBackend],
        );
        if ($waiting) {
            $write('blocked', ['blocked_pid' => (int) $waiting->pid, 'query' => $waiting->query]);
            Illuminate\Support\Facades\DB::commit();
            Illuminate\Support\Facades\Http::assertNothingSent();
            exit(0);
        }
        usleep(10000);
    } while (microtime(true) < $deadline);
    throw new RuntimeException('Deletion never waited for the uncommitted material transaction.');
} catch (Throwable $error) {
    while (Illuminate\Support\Facades\DB::transactionLevel() > 0) {
        Illuminate\Support\Facades\DB::rollBack();
    }
    fwrite(STDERR, $error->getMessage()."\n");
    exit(1);
}
PHP;
    }
}
