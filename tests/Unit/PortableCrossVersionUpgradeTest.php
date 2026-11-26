<?php

declare(strict_types=1);

namespace Tests\Unit;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\TestCase;
use SQLite3;
use Symfony\Component\Process\Process;
use Yanji\Portable\Installation;
use Yanji\Portable\Instance;

require_once __DIR__.'/../../portable/src/Instance.php';
require_once __DIR__.'/../../portable/src/Installation.php';

class PortableCrossVersionUpgradeTest extends TestCase
{
    private string $directory;

    private string $homeDir;

    private string $fakePython;

    /** @var list<string> */
    private array $instancesToStop = [];

    protected function setUp(): void
    {
        $raw = sys_get_temp_dir().'/yanji-upgrade-test-'.bin2hex(random_bytes(8));
        mkdir($raw, 0700, true);
        $this->directory = (string) realpath($raw);
        $this->homeDir = $this->directory.'/home';
        mkdir($this->homeDir, 0700, true);

        $this->fakePython = $this->directory.'/fake-python';
        if (PHP_OS_FAMILY !== 'Windows') {
            file_put_contents($this->fakePython, "#!/bin/sh\nprintf '%s' '{\"basic\":true,\"version\":\"3.12.16\"}'\n");
            chmod($this->fakePython, 0700);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->instancesToStop as $instRoot) {
            if (is_dir($instRoot) && is_file($instRoot.'/yanji')) {
                $p = new Process([$instRoot.'/yanji', 'stop'], null, $this->isolatedEnv(), null, 15);
                $p->run();
            }
        }
        (new Filesystem)->deleteDirectory($this->directory);
    }

    /** @return array<string, string> */
    private function isolatedEnv(): array
    {
        return [
            'HOME' => $this->homeDir,
            'XDG_DATA_HOME' => $this->homeDir.'/.local/share',
            'XDG_CONFIG_HOME' => $this->homeDir.'/.config',
            'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        ];
    }

    private function freePort(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        self::assertIsResource($sock, (string) $errstr);
        $name = (string) stream_socket_get_name($sock, false);
        fclose($sock);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private function buildFakeUnixRelease(string $dirName, string $version, bool $failMigrate = false): string
    {
        $repoRoot = dirname(__DIR__, 2);
        $release = $this->directory.'/programs/'.$dirName;
        foreach ([
            'app/portable/src',
            'app/app/Services/Backup',
            'app/public',
            'runtime/bin',
            'runtime/ca',
        ] as $sub) {
            mkdir($release.'/'.$sub, 0700, true);
        }

        foreach (['yanji.php', 'yanji', 'yanji.cmd', 'Caddyfile', 'instance.env.example', 'settle-timeout.php'] as $file) {
            copy($repoRoot.'/portable/'.$file, $release.'/app/portable/'.$file);
        }
        chmod($release.'/app/portable/yanji', 0700);
        foreach (glob($repoRoot.'/portable/src/*.php') ?: [] as $srcFile) {
            copy($srcFile, $release.'/app/portable/src/'.basename($srcFile));
        }
        copy(
            $repoRoot.'/app/Services/Backup/SqliteSnapshot.php',
            $release.'/app/app/Services/Backup/SqliteSnapshot.php',
        );

        file_put_contents($release.'/app/VERSION', $version."\n");
        file_put_contents($release.'/release.json', json_encode(['version' => $version, 'platform' => 'unix-test'])."\n");
        file_put_contents($release.'/ .archive-sha256', str_repeat('a', 64)."\n");
        rename($release.'/ .archive-sha256', $release.'/.archive-sha256');
        file_put_contents($release.'/app/public/index.php', "<?php echo 'ok';\n");
        file_put_contents($release.'/runtime/ca/cacert.pem', "test-ca\n");

        $scanned = php_ini_scanned_files();
        $scanDirLine = '';
        if (is_string($scanned) && trim($scanned) !== '') {
            $firstIni = trim(explode(',', $scanned)[0]);
            if ($firstIni !== '') {
                $scanDirLine = 'export PHP_INI_SCAN_DIR='.escapeshellarg(dirname($firstIni))."\n";
            }
        }
        file_put_contents($release.'/runtime/bin/php', "#!/bin/sh\n".$scanDirLine.'exec '.escapeshellarg(PHP_BINARY).' "$@"'."\n");
        chmod($release.'/runtime/bin/php', 0700);

        $fakeWeb = <<<'PHP'
<?php
declare(strict_types=1);
pcntl_async_signals(true);
$stop = false;
pcntl_signal(SIGTERM, static function () use (&$stop): void { $stop = true; });
pcntl_signal(SIGINT, static function () use (&$stop): void { $stop = true; });
$port = (int) (getenv('YANJI_PORT') ?: 8000);
$srv = @stream_socket_server('tcp://127.0.0.1:'.$port, $errno, $err);
if (!$srv) {
    exit(1);
}
while (!$stop) {
    $read = [$srv];
    $w = $e = null;
    if (@stream_select($read, $w, $e, 0, 100000) === 1) {
        $conn = @stream_socket_accept($srv, 1);
        if ($conn) {
            @fread($conn, 2048);
            @fwrite($conn, "HTTP/1.1 200 OK\r\nContent-Type: text/plain\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");
            fclose($conn);
        }
    }
}
fclose($srv);
exit(0);
PHP;
        file_put_contents($release.'/runtime/bin/frankenphp-server.php', $fakeWeb);
        file_put_contents(
            $release.'/runtime/bin/frankenphp',
            "#!/bin/sh\n".$scanDirLine.'exec '.escapeshellarg(PHP_BINARY).' '.escapeshellarg($release.'/runtime/bin/frankenphp-server.php')."\n",
        );
        chmod($release.'/runtime/bin/frankenphp', 0700);

        $failFlag = $failMigrate ? 'true' : 'false';
        $verExport = var_export($version, true);
        $fakeArtisan = <<<PHP
<?php
declare(strict_types=1);
\$dbPath = (string) getenv('DB_DATABASE');
\$envDir = (string) getenv('YANJI_ENV_PATH');
\$envFile = \$envDir.'/.env';
\$cmd = \$argv[1] ?? '';

function openDb(string \$path): SQLite3 {
    \$db = new SQLite3(\$path);
    \$db->busyTimeout(5000);
    \$db->exec('PRAGMA journal_mode=WAL');
    \$db->exec('CREATE TABLE IF NOT EXISTS projects (name TEXT NOT NULL)');
    \$db->exec('CREATE TABLE IF NOT EXISTS worker_heartbeats (worker_id TEXT PRIMARY KEY, status TEXT, meta TEXT, last_seen_at TEXT)');
    return \$db;
}

if (\$cmd === 'yanji:install') {
    \$env = is_file(\$envFile) ? (string) file_get_contents(\$envFile) : '';
    if (preg_match('/^APP_KEY=\\s*\$/m', \$env)) {
        \$env = (string) preg_replace('/^APP_KEY=.*\$/m', 'APP_KEY=base64:sk-test-portable-key', \$env);
        file_put_contents(\$envFile, \$env);
    }
    \$db = openDb(\$dbPath);
    \$db->exec("INSERT INTO projects (name) VALUES ('seed-' || {$verExport})");
    \$db->close();
    exit(0);
}

if (\$cmd === 'yanji:ready') {
    if (!is_file(\$envFile) || !is_file(\$dbPath) || filesize(\$dbPath) === 0) {
        exit(1);
    }
    \$env = (string) file_get_contents(\$envFile);
    if (!preg_match('/^APP_KEY=[^\\r\\n]+\$/m', \$env)) {
        exit(1);
    }
    try {
        \$db = new SQLite3(\$dbPath, SQLITE3_OPEN_READONLY);
        \$db->busyTimeout(5000);
        \$ok = \$db->querySingle("SELECT name FROM sqlite_master WHERE type='table' AND name='projects'");
        \$db->close();
        exit(\$ok === 'projects' ? 0 : 1);
    } catch (Throwable) {
        exit(1);
    }
}

if (\$cmd === 'migrate') {
    \$db = openDb(\$dbPath);
    if ({$failFlag}) {
        \$db->exec("INSERT INTO projects (name) VALUES ('corrupted-by-failed-migration')");
        \$db->close();
        exit(1);
    }
    \$db->exec("INSERT INTO projects (name) VALUES ('migrated-' || {$verExport})");
    \$db->close();
    exit(0);
}

if (\$cmd === 'yanji:worker' && in_array('--check', \$argv, true)) {
    exit(0);
}

if (\$cmd === 'schedule:list') {
    exit(0);
}

if (\$cmd === 'yanji:desktop-launch') {
    \$out = null;
    foreach (\$argv as \$arg) {
        if (str_starts_with(\$arg, '--output=')) {
            \$out = substr(\$arg, strlen('--output='));
        }
    }
    \$port = getenv('YANJI_PORT') ?: '8000';
    \$payload = json_encode([
        'url' => 'http://127.0.0.1:'.\$port.'/local-start#ticket='.str_repeat('b', 64),
        'expires_at' => time() + 120,
    ]);
    file_put_contents((string) \$out, (string) \$payload);
    chmod((string) \$out, 0600);
    exit(0);
}

if (\$cmd === 'yanji:worker' || \$cmd === 'schedule:work') {
    pcntl_async_signals(true);
    \$stop = false;
    pcntl_signal(SIGTERM, static function () use (&\$stop): void { \$stop = true; });
    pcntl_signal(SIGINT, static function () use (&\$stop): void { \$stop = true; });
    \$workerId = \$cmd === 'schedule:work' ? 'scheduler' : 'portable-worker';
    \$gen = (string) getenv('YANJI_SERVICE_GENERATION');
    \$stopFile = (string) getenv('YANJI_STOP_FILE');
    \$pid = getmypid();
    while (!\$stop && (\$stopFile === '' || !is_file(\$stopFile))) {
        try {
            \$db = openDb(\$dbPath);
            \$stmt = \$db->prepare('INSERT OR REPLACE INTO worker_heartbeats (worker_id, status, meta, last_seen_at) VALUES (:id, :st, :meta, :ts)');
            \$stmt->bindValue(':id', \$workerId);
            \$stmt->bindValue(':st', 'running');
            \$stmt->bindValue(':meta', json_encode(['generation' => \$gen, 'pid' => \$pid]));
            \$stmt->bindValue(':ts', gmdate('Y-m-d H:i:s'));
            \$stmt->execute();
            \$db->close();
        } catch (Throwable) {
        }
        usleep(100000);
    }
    exit(0);
}

exit(0);
PHP;
        file_put_contents($release.'/app/artisan', $fakeArtisan);
        chmod($release.'/app/artisan', 0700);

        return $release;
    }

    public function test_should_upgrade_existing_rejects_corrupted_or_conflicting_flag_instances(): void
    {
        $instRoot = $this->directory.'/guard-instance';
        $instance = new Instance($instRoot);
        $instance->prepare();

        $oldRel = $this->directory.'/rel-old';
        $newRel = $this->directory.'/rel-new';
        foreach ([$oldRel, $newRel] as $rel) {
            mkdir($rel.'/app/public', 0700, true);
            mkdir($rel.'/runtime/bin', 0700, true);
            touch($rel.'/app/artisan');
            touch($rel.'/app/public/index.php');
            $phpBin = $rel.'/runtime/bin/'.(PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
            touch($phpBin);
            chmod($phpBin, 0700);
        }
        $instance->activate($oldRel);
        Instance::atomicFile($instance->root.'/instance.json', '{"port":8140}');
        Instance::atomicFile($instance->root.'/.portable-initialized', "verified\n");
        Instance::atomicFile($instance->root.'/.env', "APP_KEY=base64:sk-test-guard\nYANJI_DESKTOP_MODE=true\n");

        // Missing SQLite database must NOT auto-upgrade; Installation::install must report corruption.
        self::assertFalse(Installation::shouldUpgradeExisting($instance, $newRel));

        // Populate database -> eligible for seamless upgrade when flags do not conflict.
        Instance::atomicFile($instance->root.'/database/database.sqlite', 'sqlite-fixture-bytes');
        self::assertTrue(Installation::shouldUpgradeExisting($instance, $newRel));
        self::assertTrue(Installation::shouldUpgradeExisting($instance, $newRel, 8140));

        // Same release uses idempotent install(), not Backup+Upgrade.
        self::assertFalse(Installation::shouldUpgradeExisting($instance, $oldRel));

        // Conflicting port or explicit account credentials must fail closed instead of silently ignoring flags.
        self::assertFalse(Installation::shouldUpgradeExisting($instance, $newRel, 9999));
        self::assertFalse(Installation::shouldUpgradeExisting($instance, $newRel, null, 'admin@example.com', null));
        self::assertFalse(Installation::shouldUpgradeExisting($instance, $newRel, null, null, $this->directory.'/pw'));
    }

    public function test_re_running_install_with_newer_release_auto_upgrades_preserving_data_and_creating_backup(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Unix portable supervisor fixture runs on macOS and Linux.');
        }

        $relV1 = $this->buildFakeUnixRelease('rel-0.1.1', '0.1.1');
        $relV2 = $this->buildFakeUnixRelease('rel-0.1.2', '0.1.2');
        $instRoot = $this->directory.'/user-instance';
        $this->instancesToStop[] = $instRoot;
        $port = $this->freePort();

        // Step 1: Fresh install v0.1.1.
        $installV1 = new Process([
            PHP_BINARY, $relV1.'/app/portable/yanji.php', '__install',
            '--instance', $instRoot, '--release', $relV1, '--port', (string) $port,
            '--python-bin', $this->fakePython, '--no-browser',
        ], null, $this->isolatedEnv(), null, 30);
        $installV1->mustRun();

        // Record user data in SQLite and private storage to prove zero data loss across auto-upgrade.
        $db = new SQLite3($instRoot.'/database/database.sqlite');
        $db->busyTimeout(5000);
        $db->exec("INSERT INTO projects (name) VALUES ('客户项目Alpha')");
        $db->close();
        mkdir($instRoot.'/storage/app/private/executions/1', 0700, true);
        file_put_contents($instRoot.'/storage/app/private/executions/1/report.md', "# 客户诊断报告\n");
        chmod($instRoot.'/storage/app/private/executions/1/report.md', 0600);
        $originalEnv = (string) file_get_contents($instRoot.'/.env');

        // Step 2: Run v0.1.2 __install WITHOUT --upgrade (exact flow when user re-runs one-line installer).
        $installV2 = new Process([
            PHP_BINARY, $relV2.'/app/portable/yanji.php', '__install',
            '--instance', $instRoot, '--release', $relV2,
            '--python-bin', $this->fakePython, '--no-browser',
        ], null, $this->isolatedEnv(), null, 30);
        $installV2->run();

        self::assertSame(0, $installV2->getExitCode(), 'Re-running install with a newer release must auto-upgrade cleanly: '.$installV2->getErrorOutput());
        self::assertStringContainsString('正在自动备份并平滑升级', $installV2->getErrorOutput());

        $instance = new Instance($instRoot);
        self::assertSame($relV2, $instance->release());
        self::assertSame($originalEnv, file_get_contents($instRoot.'/.env'));
        self::assertSame("# 客户诊断报告\n", file_get_contents($instRoot.'/storage/app/private/executions/1/report.md'));
        self::assertFileDoesNotExist($instRoot.'/run/upgrade-hold');

        $dbAfter = new SQLite3($instRoot.'/database/database.sqlite', SQLITE3_OPEN_READONLY);
        $dbAfter->busyTimeout(5000);
        $names = [];
        $res = $dbAfter->query('SELECT name FROM projects');
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $names[] = $row['name'];
        }
        $dbAfter->close();
        self::assertContains('客户项目Alpha', $names);
        self::assertContains('migrated-0.1.2', $names);

        // Verify recovery backup zip was created outside instance root.
        $backups = glob($this->directory.'/.yanji-recovery-*/instance.zip') ?: [];
        self::assertNotEmpty($backups, 'Auto-upgrade must create a pre-upgrade recovery backup zip.');

        // Verify upgraded services are running and writes_enabled is true.
        $statusProc = new Process([$instRoot.'/yanji', 'status'], null, $this->isolatedEnv(), null, 15);
        $statusProc->mustRun();
        $status = json_decode($statusProc->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($status['ready']);
        self::assertTrue($status['writes_enabled']);
    }

    public function test_auto_upgrade_rolls_back_release_and_database_when_candidate_migration_fails(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('Unix portable supervisor fixture runs on macOS and Linux.');
        }

        $relGood = $this->buildFakeUnixRelease('rel-good-0.1.2', '0.1.2');
        $relBad = $this->buildFakeUnixRelease('rel-bad-0.1.3', '0.1.3', failMigrate: true);
        $instRoot = $this->directory.'/rollback-instance';
        $this->instancesToStop[] = $instRoot;
        $port = $this->freePort();

        $installGood = new Process([
            PHP_BINARY, $relGood.'/app/portable/yanji.php', '__install',
            '--instance', $instRoot, '--release', $relGood, '--port', (string) $port,
            '--python-bin', $this->fakePython, '--no-browser',
        ], null, $this->isolatedEnv(), null, 30);
        $installGood->mustRun();

        $db = new SQLite3($instRoot.'/database/database.sqlite');
        $db->busyTimeout(5000);
        $db->exec("INSERT INTO projects (name) VALUES ('回滚保护项目')");
        $db->close();

        $installBad = new Process([
            PHP_BINARY, $relBad.'/app/portable/yanji.php', '__install',
            '--instance', $instRoot, '--release', $relBad,
            '--python-bin', $this->fakePython, '--no-browser',
        ], null, $this->isolatedEnv(), null, 30);
        $installBad->run();

        self::assertSame(1, $installBad->getExitCode());
        self::assertStringContainsString('升级失败，旧版本及匹配数据已恢复', $installBad->getErrorOutput());

        $instance = new Instance($instRoot);
        self::assertSame($relGood, $instance->release());
        self::assertFileDoesNotExist($instRoot.'/run/upgrade-hold');

        $dbRolledBack = new SQLite3($instRoot.'/database/database.sqlite', SQLITE3_OPEN_READONLY);
        $dbRolledBack->busyTimeout(5000);
        $names = [];
        $res = $dbRolledBack->query('SELECT name FROM projects');
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $names[] = $row['name'];
        }
        $dbRolledBack->close();
        self::assertContains('回滚保护项目', $names);
        self::assertNotContains('corrupted-by-failed-migration', $names);
    }

    public function test_unix_supervisor_closes_inherited_agent_pipes_and_survives_sighup_and_launcher_group_kill(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            self::markTestSkipped('POSIX double-fork, SIGHUP and inherited FD test runs on macOS and Linux.');
        }

        $rel = $this->buildFakeUnixRelease('rel-daemon', '0.1.3');
        $instRoot = $this->directory.'/daemon-instance';
        $this->instancesToStop[] = $instRoot;
        $port = $this->freePort();

        $install = new Process([
            PHP_BINARY, $rel.'/app/portable/yanji.php', '__install',
            '--instance', $instRoot, '--release', $rel, '--port', (string) $port,
            '--python-bin', $this->fakePython, '--no-browser',
        ], null, $this->isolatedEnv(), null, 30);
        $install->mustRun();

        $stop = new Process([$instRoot.'/yanji', 'stop'], null, $this->isolatedEnv(), null, 15);
        $stop->mustRun();

        // Simulate an AI agent tool runner (e.g., WorkBuddy / Claude Code / Node IPC) passing an open pipe FD 7
        // into the shell command and killing its process group upon exit.
        $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
        self::assertIsArray($pair);
        [$parentRead, $inheritedWrite] = $pair;

        $launcherPidFile = $this->directory.'/launcher.pid';
        $agentRunner = <<<'SH'
set -eu
echo "$$" > "$1"
"$2" start --no-browser
SH;
        $descriptors = [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
            7 => $inheritedWrite,
        ];
        $proc = proc_open(
            ['/bin/sh', '-c', $agentRunner, 'agent-sh', $launcherPidFile, $instRoot.'/yanji'],
            $descriptors,
            $pipes,
            null,
            $this->isolatedEnv(),
        );
        self::assertIsResource($proc);
        fclose($inheritedWrite);

        $exitCode = proc_close($proc);
        self::assertSame(0, $exitCode);

        // If Supervisor leaked FD 7 into the background daemon, $parentRead would NOT reach EOF here!
        $read = [$parentRead];
        $write = $except = null;
        $ready = stream_select($read, $write, $except, 1, 500000);
        stream_set_blocking($parentRead, false);
        $eofChunk = fread($parentRead, 64);
        $isEof = feof($parentRead);
        fclose($parentRead);
        self::assertSame(1, $ready, 'Inherited agent pipe FD 7 did not close after yanji start exited (daemon leaked pipe FD!).');
        self::assertSame('', (string) $eofChunk);
        self::assertTrue($isEof, 'Inherited agent pipe FD 7 remained open in background daemon.');

        // Verify Supervisor survives SIGHUP and process-group teardown of the agent shell.
        $statusBefore = new Process([$instRoot.'/yanji', 'status'], null, $this->isolatedEnv(), null, 10);
        $statusBefore->mustRun();
        $info = json_decode($statusBefore->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($info['ready']);
        $managerPid = (int) $info['manager_pid'];
        self::assertGreaterThan(1, $managerPid);

        $launcherPid = (int) trim((string) file_get_contents($launcherPidFile));
        self::assertNotSame($launcherPid, $managerPid);
        posix_kill($managerPid, SIGHUP);
        usleep(150000);

        $statusAfter = new Process([$instRoot.'/yanji', 'status'], null, $this->isolatedEnv(), null, 10);
        $statusAfter->mustRun();
        $afterInfo = json_decode($statusAfter->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertTrue($afterInfo['ready'], 'Supervisor died on SIGHUP after agent terminal closed.');
        self::assertSame($managerPid, $afterInfo['manager_pid']);
    }
}
