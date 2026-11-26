<?php

namespace App\Support;

use App\Models\User;
use App\Services\Auth\LocalAccounts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Yanji\Portable\Platform;

final class PortableDesktopAccess
{
    private const LIFETIME_SECONDS = 120;

    public static function enabled(): bool
    {
        if (config('instance.portable') !== true || config('instance.desktop_mode') !== true) {
            return false;
        }
        $root = (string) config('instance.portable_root');
        $marker = rtrim($root, '/\\').'/.portable-layout';
        $url = parse_url((string) config('app.url'));

        return $root !== '' && is_dir($root) && ! is_link($root)
            && is_file($marker) && ! is_link($marker)
            && file_get_contents($marker, false, null, 0, 64) === "yanji-portable-v1\n"
            && is_array($url) && ($url['scheme'] ?? '') === 'http' && ($url['host'] ?? '') === '127.0.0.1'
            && ! isset($url['user']) && ! isset($url['pass']) && ! isset($url['query']) && ! isset($url['fragment'])
            && in_array($url['path'] ?? '', ['', '/'], true);
    }

    /** Also used before the first internal workspace has been created. */
    public static function assertEnabled(): void
    {
        if (! self::enabled()) {
            throw new RuntimeException('本机自动进入仅适用于已启用单人模式的本机便携实例。');
        }
    }

    /** The launcher reads this private file; credentials never go to console output. */
    public static function createLaunchFile(string $output): void
    {
        self::assertEnabled();
        $run = self::privateRunDirectory();
        if (self::normalize(dirname($output)) !== self::normalize($run)
            || preg_match('/\Abrowser-launch-[A-Za-z0-9_-]{8,80}\.json\z/D', basename($output)) !== 1
            || file_exists($output) || is_link($output)) {
            throw new RuntimeException('浏览器启动文件必须是实例运行目录内的新私有文件。');
        }
        $user = self::singleOwner();
        $ticket = bin2hex(random_bytes(32));
        $expires = now()->getTimestamp() + self::LIFETIME_SECONDS;
        $record = ['fingerprint' => hash('sha256', $ticket), 'user_id' => $user->id,
            'tenant_id' => $user->tenant_id, 'binding' => self::binding($user), 'expires_at' => $expires];
        self::withTicketFile(function ($file) use ($record): void {
            self::writeRecord($file, json_encode($record, JSON_THROW_ON_ERROR));
        });

        $mask = umask(0077);
        try {
            $file = @fopen($output, 'x+b');
        } finally {
            umask($mask);
        }
        if (! $file) {
            throw new RuntimeException('无法创建浏览器启动文件。');
        }
        try {
            self::platform();
            Platform::protectPrivate($output, false);
            self::writeRecord($file, json_encode([
                'url' => rtrim((string) config('app.url'), '/').'/local-start#ticket='.$ticket,
                'expires_at' => $expires,
            ], JSON_THROW_ON_ERROR));
        } finally {
            fclose($file);
        }
    }

    public static function exchange(Request $request): JsonResponse
    {
        self::assertLocalRequest($request);
        $payload = $request->json()->all();
        if (! array_key_exists('ticket', $payload)) {
            return self::issueDirectLoopbackSession($request);
        }
        $ticket = $payload['ticket'];
        abort_unless(is_string($ticket) && preg_match('/\A[a-f0-9]{64}\z/D', $ticket) === 1,
            403, '本机启动授权无效或已过期，请重新打开工作台。');

        try {
            return self::withTicketFile(function ($file) use ($ticket, $request): JsonResponse {
                $record = json_decode((string) stream_get_contents($file, 4097), true);
                abort_unless(is_array($record) && is_int($record['expires_at'] ?? null)
                    && $record['expires_at'] > now()->getTimestamp()
                    && $record['expires_at'] <= now()->getTimestamp() + self::LIFETIME_SECONDS
                    && is_string($record['fingerprint'] ?? null)
                    && hash_equals($record['fingerprint'], hash('sha256', $ticket)),
                    403, '本机启动授权无效或已过期，请重新打开工作台。');
                return DB::transaction(function () use ($file, $record, $request): JsonResponse {
                    // Account creation also locks instance_settings. Keep identity validation and issuance together.
                    DB::table('instance_settings')->where('id', 1)->lockForUpdate()->first();
                    $user = self::singleOwner(true);
                    abort_unless((int) ($record['user_id'] ?? 0) === (int) $user->id
                        && (int) ($record['tenant_id'] ?? 0) === (int) $user->tenant_id
                        && is_string($record['binding'] ?? null) && hash_equals($record['binding'], self::binding($user)),
                        403, '本机启动授权与当前工作区不符，请重新打开工作台。');
                    // Consume under the file lock before issuing a session. A failed response cannot replay it.
                    self::writeRecord($file, '');

                    return self::issueOrReuseOwnerSession($user, $request);
                })
                    ->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
            });
        } catch (RuntimeException) {
            abort(403, '本机启动授权不可用，请重新打开工作台。');
        }
    }

    private static function issueDirectLoopbackSession(Request $request): JsonResponse
    {
        try {
            self::privateRunDirectory();
            return DB::transaction(function () use ($request): JsonResponse {
                DB::table('instance_settings')->where('id', 1)->lockForUpdate()->first();
                $user = self::singleOwner(true);
                self::binding($user);

                return self::issueOrReuseOwnerSession($user, $request);
            })->header('Cache-Control', 'no-store, private')->header('Pragma', 'no-cache');
        } catch (RuntimeException) {
            abort(403, '本机工作台会话暂不可用，请检查实例状态后重试。');
        }
    }

    private static function issueOrReuseOwnerSession(User $user, Request $request): JsonResponse
    {
        $existingCookie = LocalSession::valueFromRequest($request);
        if ($existingCookie !== '') {
            $active = DB::table('local_browser_sessions')
                ->where('fingerprint', hash('sha256', $existingCookie))
                ->where('user_id', $user->id)
                ->where('expires_at', '>', now())
                ->exists();
            if ($active) {
                return LocalSession::issue($user, $request, $existingCookie);
            }
        }

        return LocalSession::issue($user, $request);
    }

    public static function assertLocalRequest(Request $request): void
    {
        abort_unless(self::enabled(), 404);
        $origin = rtrim((string) config('app.url'), '/');
        $fetchSite = strtolower(trim((string) $request->headers->get('Sec-Fetch-Site', '')));
        abort_unless($request->isJson() && $request->headers->get('Origin') === $origin
            && ($fetchSite === '' || $fetchSite === 'same-origin')
            && $request->server('REMOTE_ADDR') === '127.0.0.1'
            && $request->getSchemeAndHttpHost() === $origin, 403, '请通过本机地址直接打开工作台。');
        foreach ($request->headers->keys() as $name) {
            if ($name === 'forwarded' || str_starts_with($name, 'x-forwarded-') || $name === 'x-real-ip') {
                abort(response()->json([
                    'message' => '检测到代理转发了本机地址（127.0.0.1），请在代理软件中绕过本地地址或关闭浏览器代理插件后重试。',
                    'error_code' => 'proxy_detected',
                ], 403));
            }
        }
    }

    private static function singleOwner(bool $lock = false): User
    {
        $query = User::withoutGlobalScopes()->limit(2);
        $users = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($users->count() !== 1 || $users->first()->role !== 'platform_admin') {
            throw new RuntimeException('本机单人模式要求唯一的本机管理员工作区。');
        }
        $user = $users->first();
        if ($lock) {
            $user->setRelation('tenant', $user->tenant()->lockForUpdate()->first());
        }
        LocalAccounts::assertActive($user);

        return $user;
    }

    private static function binding(User $user): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new RuntimeException('实例密钥未配置。');
        }

        return hash_hmac('sha256', json_encode([self::normalize((string) realpath(config('instance.portable_root'))),
            rtrim((string) config('app.url'), '/'), $user->id, $user->tenant_id], JSON_THROW_ON_ERROR), $key);
    }

    private static function platform(): void
    {
        require_once base_path('portable/src/Platform.php');
    }

    private static function normalize(string $path): string
    {
        $path = rtrim(str_replace('\\', '/', $path), '/');

        return PHP_OS_FAMILY === 'Windows' ? strtolower($path) : $path;
    }

    private static function privateRunDirectory(): string
    {
        self::platform();
        $root = (string) config('instance.portable_root');
        $run = rtrim($root, '/\\').'/run';
        if (! Platform::isPrivate($root) || ! is_dir($run) || ! Platform::isPrivate($run)
            || self::normalize((string) realpath($run)) !== self::normalize((string) realpath($root).'/run')) {
            throw new RuntimeException('本机运行目录必须是当前用户的私有目录。');
        }

        return $run;
    }

    private static function withTicketFile(callable $callback): mixed
    {
        $path = self::privateRunDirectory().'/desktop-ticket.json';
        if (is_link($path) || (file_exists($path) && (! is_file($path) || ! Platform::isPrivate($path)))) {
            throw new RuntimeException('本机启动授权文件无效。');
        }
        $mask = umask(0077);
        try {
            $file = @fopen($path, 'c+b');
        } finally {
            umask($mask);
        }
        if (! $file) {
            throw new RuntimeException('无法读取本机启动授权。');
        }
        try {
            Platform::protectPrivate($path, false);
            if (! flock($file, LOCK_EX)) {
                throw new RuntimeException('无法锁定本机启动授权。');
            }
            $stat = fstat($file);
            $current = @lstat($path);
            if (! $stat || ! $current || ($stat['mode'] & 0170000) !== 0100000
                || $stat['dev'] !== $current['dev'] || $stat['ino'] !== $current['ino'] || is_link($path)) {
                throw new RuntimeException('本机启动授权文件已变化。');
            }

            return $callback($file);
        } finally {
            flock($file, LOCK_UN);
            fclose($file);
        }
    }

    private static function writeRecord($file, string $contents): void
    {
        if (! rewind($file) || ! ftruncate($file, 0) || fwrite($file, $contents) !== strlen($contents) || ! fflush($file)) {
            throw new RuntimeException('无法保存本机启动授权。');
        }
    }
}
