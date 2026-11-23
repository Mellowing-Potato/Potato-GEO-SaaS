<?php

namespace App\Support;

use Dotenv\Dotenv;
use Illuminate\Database\Connection;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\DB;
use PDO;
use Pdo\Sqlite;
use RuntimeException;
use Throwable;

final class InstanceKey
{
    private const ID = 'YANJI_INSTANCE_ID';
    private const PENDING = 'YANJI_INSTALL_PENDING';
    private const MESSAGE = '无法验证本实例的密钥与数据库，请检查配套的 .env 和备份。';

    private static function values(string $contents): array
    {
        try {
            $env = Dotenv::parse($contents);
        } catch (Throwable) {
            throw new RuntimeException('实例配置格式无效。');
        }
        foreach (['APP_KEY', self::ID, self::PENDING] as $key) {
            if (preg_match_all('/^[ \t]*(?:export[ \t]+)?'.preg_quote($key, '/').'\s*=/m', $contents) > 1) {
                throw new RuntimeException('实例配置含重复的密钥或实例标识。');
            }
        }
        return [(string) ($env['APP_KEY'] ?? ''), (string) ($env[self::ID] ?? ''), (string) ($env[self::PENDING] ?? '')];
    }

    private static function encrypter(string $key): Encrypter
    {
        $raw = str_starts_with($key, 'base64:') ? base64_decode(substr($key, 7), true) : $key;
        if (! is_string($raw) || ! Encrypter::supported($raw, config('app.cipher'))) {
            throw new RuntimeException(self::MESSAGE);
        }
        // Deliberately use only the current key. Previous keys cannot validate a different instance.
        return new Encrypter($raw, config('app.cipher'));
    }

    /** Validate before changing the environment or running migrations on an existing database. */
    public static function prepareInstall(string $path): void
    {
        InstanceEnvironment::update($path, function (?string $contents) {
            $contents ??= 'APP_URL='.config('app.url')."\n";
            [$key, $id, $pending] = self::values($contents);
            $configured = (string) config('app.key');
            if ($key !== '' && $configured !== '' && ! hash_equals($key, $configured)) {
                throw new RuntimeException(self::MESSAGE);
            }
            $hasSavedKey = $key !== '';
            $savedKey = $hasSavedKey ? $key : $configured;
            $key = $savedKey !== '' ? $savedKey : 'base64:'.base64_encode(Encrypter::generateKey(config('app.cipher')));
            $cipher = self::encrypter($key);
            if ($id !== '' && ! preg_match('/^[a-f0-9]{32}$/D', $id)) {
                throw new RuntimeException(self::MESSAGE);
            }
            self::createFreshSqliteFile($id !== '' || $pending !== '');
            $db = DB::connection();
            $location = $db->getDatabaseName();
            if ($db->getDriverName() === 'sqlite' && is_file($location)) {
                $stat = stat($location);
                $location = realpath($location).'|'.$stat['dev'].'|'.$stat['ino'];
            }
            $proof = hash_hmac('sha256', json_encode([$db->getDriverName(), $db->getConfig('host'), $db->getConfig('port'), $location], JSON_THROW_ON_ERROR), $cipher->getKey());
            self::inspect(function (Connection $db) use ($key, $id, $pending, $proof) {
                $schema = $db->getSchemaBuilder();
                if ($schema->hasTable('users') && $db->table('users')->exists()) {
                    throw new RuntimeException('已有本地账号，拒绝重新初始化。');
                }
                if ($schema->getTableListing(schemaQualified: false) === []) {
                    if ($id !== '' || ($pending !== '' && ! hash_equals($proof, $pending))) {
                        throw new RuntimeException(self::MESSAGE);
                    }
                    return;
                }
                // A previous migration may have stopped after saving the key but before creating an account.
                if (! hash_equals($proof, $pending) || ! $schema->hasTable('migrations')
                    || ($schema->hasTable('tenants') && $db->table('tenants')->exists())) {
                    throw new RuntimeException(self::MESSAGE);
                }
                if ($schema->hasColumn('instance_settings', 'key_verification')
                    && $db->table('instance_settings')->where('id', 1)->value('key_verification') !== null) {
                    self::verify($db, $key, $id, true);
                }
            });
            if (! $hasSavedKey) {
                $contents = self::set($contents, 'APP_KEY', $key);
            }
            config(['app.key' => $key]);
            return self::set($contents, self::PENDING, $proof);
        });
    }

    private static function createFreshSqliteFile(bool $hasIdentity): void
    {
        $db = DB::connection();
        $path = $db->getDatabaseName();
        if ($db->getDriverName() !== 'sqlite' || $path === ':memory:' || str_starts_with($path, 'file:')) {
            return;
        }
        if (is_link($path)) {
            throw new RuntimeException(self::MESSAGE);
        }
        if (file_exists($path)) {
            return;
        }
        if ($hasIdentity || ! is_dir(dirname($path))) {
            throw new RuntimeException(self::MESSAGE);
        }
        $mask = umask(0077);
        try {
            $file = @fopen($path, 'x+b');
        } finally {
            umask($mask);
        }
        if (! $file) {
            throw new RuntimeException('无法创建全新的 SQLite 文件。');
        }
        fclose($file);
    }

    private static function set(string $contents, string $name, string $value): string
    {
        $line = $name.'='.$value;
        $pattern = '/^[ \t]*(?:export[ \t]+)?'.$name.'[ \t]*=[^\r\n]*/m';
        if (preg_match($pattern, $contents)) {
            return preg_replace_callback($pattern, static fn () => $line, $contents);
        }
        return $contents.(str_ends_with($contents, "\n") || $contents === '' ? '' : "\n").$line."\n";
    }

    private static function saved(string $path): array
    {
        if (is_link($path) || ! is_file($path) || ($contents = file_get_contents($path)) === false) {
            throw new RuntimeException(self::MESSAGE);
        }
        [$key, $id] = self::values($contents);
        if ($key === '' || ! hash_equals($key, (string) config('app.key'))) {
            throw new RuntimeException(self::MESSAGE);
        }
        return [$key, $id];
    }

    /** Call inside the account creation transaction with the instance_settings row locked. */
    public static function bind(string $path): void
    {
        InstanceEnvironment::update($path, function (?string $contents) {
            [$key, $id] = self::values($contents ?? '');
            if ($key === '' || ! hash_equals($key, (string) config('app.key'))) {
                throw new RuntimeException(self::MESSAGE);
            }
            $query = DB::table('instance_settings')->where('id', 1);
            if ((clone $query)->value('key_verification') !== null) {
                self::verify(DB::connection(), $key, $id, true);
                return null;
            }
            $id = $id !== '' ? $id : bin2hex(random_bytes(16));
            if (! preg_match('/^[a-f0-9]{32}$/D', $id) || (clone $query)->whereNull('key_verification')->update([
                'key_verification' => self::encrypter($key)->encryptString('yanji-instance-v1:'.$id),
            ]) !== 1) {
                throw new RuntimeException(self::MESSAGE);
            }
            return self::set($contents, self::ID, $id);
        });
    }

    /** Keep the receipt until the DB transaction commits so an interrupted first install can resume. */
    public static function finishInstall(string $path): void
    {
        InstanceEnvironment::update($path, static fn (?string $contents) => $contents === null ? null
            : preg_replace('/^'.self::PENDING.'=[a-f0-9]{64}(?:\r?\n|$)/m', '', $contents));
    }

    public static function assertReady(string $path, bool $portable): void
    {
        [$key, $id] = self::saved($path);
        self::inspect(function (Connection $db) use ($key, $id, $portable) {
            if (! $db->table('users')->where('role', 'platform_admin')->exists()) {
                throw new RuntimeException('本实例尚未初始化。');
            }
            self::verify($db, $key, $id, $portable);
        });
    }

    private static function inspect(callable $check): void
    {
        $database = DB::connection();
        if ($database->getDriverName() !== 'sqlite' || $database->getDatabaseName() === ':memory:') {
            $check($database);
            return;
        }
        // The application's normal first connection sets journal_mode=WAL, which changes an
        // existing file even if we only SELECT and reject it. Validate using an OS read-only handle.
        $config = $database->getConfig();
        $config['name'] = 'yanji_instance_inspection';
        unset($config['journal_mode'], $config['synchronous'], $config['pragmas']);
        $config['options'][class_exists(Sqlite::class) ? Sqlite::ATTR_OPEN_FLAGS : PDO::SQLITE_ATTR_OPEN_FLAGS]
            = class_exists(Sqlite::class) ? Sqlite::OPEN_READONLY : PDO::SQLITE_OPEN_READONLY;
        $readOnly = DB::build($config);
        try {
            $check($readOnly);
        } finally {
            DB::purge($readOnly->getName());
        }
    }

    private static function verify(Connection $db, string $key, string $id, bool $portable): void
    {
        $cipher = self::encrypter($key);
        $schema = $db->getSchemaBuilder();
        $witness = $schema->hasColumn('instance_settings', 'key_verification')
            ? $db->table('instance_settings')->where('id', 1)->value('key_verification') : null;
        if ($witness !== null) {
            if (! preg_match('/^[a-f0-9]{32}$/D', $id) || ! hash_equals('yanji-instance-v1:'.$id, $cipher->decryptString($witness))) {
                throw new RuntimeException(self::MESSAGE);
            }
            return;
        }
        if ($id !== '') {
            throw new RuntimeException(self::MESSAGE);
        }
        // Installations predating the marker can demonstrate key continuity with authenticated ciphertext.
        foreach (['model_connection_profiles' => 'credential', 'tenant_ai_keys' => 'encrypted_key',
            'publishing_channels' => 'encrypted_secret', 'wechat_official_account_connections' => 'app_secret',
            'browser_draft_deliveries' => 'payload', 'wechat_draft_deliveries' => 'payload',
            'api_idempotency_keys' => 'model_checkpoint', 'skill_executions' => 'billing_pending_result'] as $table => $column) {
            if ($schema->hasColumn($table, $column)) {
                $sample = $db->table($table)->whereNotNull($column)->value($column);
                if ($sample !== null) {
                    $cipher->decryptString($sample);
                    return;
                }
            }
        }
        if ($portable) {
            throw new RuntimeException(self::MESSAGE);
        }
    }
}
