<?php

namespace Tests\Feature;

use App\Models\{KnowledgeBase, Tenant, User};
use App\Support\TenantContext;
use Illuminate\Support\Facades\{DB, Http, Storage};
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Process\Process;
use Tests\Support\{UsesFileSqlite, UsesWorkspaceModels, KnowledgeProjectFixtures};
use Tests\TestCase;

class KnowledgeDeletionTransactionTest extends TestCase
{
    use UsesFileSqlite, UsesWorkspaceModels, KnowledgeProjectFixtures;

    public function test_whole_base_delete_waits_for_a_registered_document_in_an_uncommitted_publish_transaction(): void
    {
        Storage::fake('local');
        Http::preventStrayRequests();
        $tenant = $this->useWorkspaceModels();
        $user = User::factory()->create(['tenant_id' => $tenant->id, 'role' => 'admin']);
        $base = KnowledgeBase::create(['tenant_id' => $tenant->id, 'project_id' => self::knowledgeProjectId($tenant->id),
            'name' => '并发删除知识库', 'status' => 'active']);
        Sanctum::actingAs($user, ['*']);
        $this->withHeader('X-Tenant', $tenant->subdomain)->withHeader('X-Project-Id', (string) $base->project_id);
        DB::statement('PRAGMA busy_timeout=3000');
        $release = tempnam(sys_get_temp_dir(), 'knowledge-commit-');
        unlink($release);
        $script = <<<'PHP'
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => $argv[1],
    'database.connections.sqlite.journal_mode' => 'WAL', 'filesystems.disks.local.root' => $argv[2]]);
Illuminate\Support\Facades\DB::purge('sqlite');
Illuminate\Support\Facades\Storage::forgetDisk('local');
App\Support\TenantContext::set(App\Models\Tenant::findOrFail((int) $argv[3]));
Illuminate\Support\Facades\DB::beginTransaction();
$service = new App\Services\Knowledge\DocumentIngestionService(new App\Services\AI\MockAiProvider);
$document = $service->stageText((int) $argv[4], '未提交材料', '真实登记后尚未提交的材料正文。');
fwrite(STDOUT, json_encode(['id' => $document->id, 'directory' => $document->storageDirectory()])."\n");
fflush(STDOUT);
$deadline = microtime(true) + 8;
while (! is_file($argv[5])) {
    if (microtime(true) > $deadline) exit(3);
    usleep(1000);
}
// Let the old deletion's unprotected SELECT finish before this commit. The
// fixed deletion instead waits at its first SQLite write, then reads fresh rows.
usleep(100000);
Illuminate\Support\Facades\DB::commit();
PHP;
        $worker = new Process([PHP_BINARY, '-r', $script, $this->databaseFile, Storage::disk('local')->path(''),
            (string) $tenant->id, (string) $base->id, $release], base_path(), ['APP_KEY' => config('app.key')]);
        $worker->setTimeout(10);
        $worker->start();
        $signalled = false;
        try {
            $this->assertTrue($worker->waitUntil(fn ($type, $output) => str_contains($output, '"directory"')), $worker->getErrorOutput());
            $registered = json_decode(trim($worker->getOutput()), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(0, DB::table('knowledge_documents')->count(), 'The separate writer has not committed yet.');
            DB::connection()->beforeExecuting(function ($sql) use (&$signalled, $release): void {
                $sql = strtolower($sql);
                if (! $signalled && (str_starts_with($sql, 'update "knowledge_bases"')
                    || str_starts_with($sql, 'select * from "knowledge_documents"'))) {
                    $signalled = true;
                    touch($release);
                }
            });
            $this->deleteJson('/api/v1/knowledge-bases/'.$base->id)->assertOk();
            $this->assertTrue($signalled, 'The writer must commit while deletion is in flight.');
            $worker->wait();
            $this->assertSame(0, $worker->getExitCode(), $worker->getErrorOutput());
            $this->assertFalse(Storage::disk('local')->directoryExists($registered['directory']), 'The newly committed source must not be orphaned by the cascade delete.');
            $this->assertDatabaseMissing('knowledge_documents', ['id' => $registered['id']]);
            $this->assertDatabaseMissing('knowledge_bases', ['id' => $base->id]);
        } finally {
            $worker->stop(0.1);
            if (is_file($release)) unlink($release);
        }
        TenantContext::forget();
        Http::assertNothingSent();
    }
}
