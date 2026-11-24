<?php

namespace App\Support;

use App\Services\AI\MagpieTargets;
use Dotenv\Dotenv;
use Dotenv\Parser\Lines;
use RuntimeException;
use stdClass;

/** Merge the selected workspace into one unambiguous deployment target. */
final class LocalMagpieAuthorization
{
    private const ENV_NAME = 'GEO_MAGPIE_TARGETS';
    private const ORIGIN = 'http://127.0.0.1:3425';

    public static function bindingHash(string $key, string $instanceId): string
    {
        return InstanceKey::bindingHash($key, $instanceId);
    }

    public function grant(string $path, int $tenantId, ?string $expectedBindingHash = null): void
    {
        if ($tenantId < 1 || ! is_file($path) || is_link($path)) {
            throw new RuntimeException('无法安全更新实例环境文件。');
        }
        InstanceEnvironment::update($path, function (?string $contents) use ($tenantId, $expectedBindingHash): ?string {
            if ($contents === null) {
                throw new RuntimeException('实例环境文件缺失，未修改授权。');
            }
            $environment = Dotenv::parse($contents);
            if ($expectedBindingHash !== null && ! hash_equals($expectedBindingHash,
                self::bindingHash($environment['APP_KEY'] ?? '', $environment['YANJI_INSTANCE_ID'] ?? ''))) {
                throw new RuntimeException('实例密钥或标识已变化，请重新核对工作区后授权。');
            }
            $span = self::assignmentSpan($contents);
            if (($span !== null) !== array_key_exists(self::ENV_NAME, $environment)) {
                throw new RuntimeException('无法确定环境文件中原授权的位置。');
            }
            $raw = $environment[self::ENV_NAME] ?? '';
            $targets = $raw === '' ? new stdClass : json_decode($raw, flags: JSON_THROW_ON_ERROR);
            if ($targets === []) {
                $targets = new stdClass;
            }
            if (! $targets instanceof stdClass) {
                throw new RuntimeException('GEO_MAGPIE_TARGETS 必须是连接别名字典。');
            }
            $alias = $this->localAlias($targets);
            $target = $targets->{$alias} ?? (object) [
                'origin' => self::ORIGIN, 'tenant_ids' => [], 'auth_mode' => 'loopback', 'enabled' => true, 'revision' => 1,
            ];
            $pins = $target->addresses ?? [];
            if (($target->auth_mode ?? null) !== 'loopback' || ($target->enabled ?? null) !== true
                || ($pins !== [] && $pins !== ['127.0.0.1'])
                || (! is_int($target->revision ?? null) && (! is_string($target->revision ?? null) || $target->revision === ''))
                || ! is_array($target->tenant_ids ?? null) || ! array_is_list($target->tenant_ids)) {
                throw new RuntimeException('已有本机目标已停用、地址受限或设置不同，请部署者核对。');
            }
            foreach ($target->tenant_ids as $id) {
                if (! is_int($id) || $id < 1) {
                    throw new RuntimeException('已有工作区授权格式无效。');
                }
            }
            if (in_array($tenantId, $target->tenant_ids, true)) {
                return null;
            }
            $target->tenant_ids[] = $tenantId;
            $targets->{$alias} = $target;
            $json = json_encode($targets, JSON_THROW_ON_ERROR | JSON_HEX_APOS | JSON_UNESCAPED_SLASHES);
            $assignment = self::ENV_NAME."='".$json."'";
            $updated = $span === null
                ? $contents.(str_ends_with($contents, "\n") || $contents === '' ? '' : "\n").$assignment."\n"
                : substr_replace($contents, $assignment, $span[0], $span[1]);
            if ((Dotenv::parse($updated)[self::ENV_NAME] ?? null) !== $json) {
                throw new RuntimeException('环境文件无法载入新授权。');
            }

            return $updated;
        });
    }

    private function localAlias(stdClass $targets): string
    {
        $found = null;
        foreach (get_object_vars($targets) as $alias => $candidate) {
            $origin = $candidate instanceof stdClass ? ($candidate->origin ?? null) : null;
            if (! is_string($origin)) {
                continue;
            }
            $url = parse_url($origin);
            if (($url['port'] ?? null) !== 3425) {
                continue;
            }
            $host = rtrim(strtolower(trim($url['host'] ?? '', '[]')), '.');
            $pins = is_array($candidate->addresses ?? null) ? $candidate->addresses : [];
            $local = $host === 'localhost' || str_ends_with($host, '.localhost') || $host === 'host.docker.internal'
                || self::loopbackAddress($host) || array_filter($pins, self::loopbackAddress(...)) !== [];
            if (! $local) {
                continue;
            }
            // These are existing declarations, never additional discovery addresses. Do not resolve hostnames.
            if (! preg_match('~\Ahttp://127\.0\.0\.1\.*:[0-9]{1,5}/?\z~i', $origin)) {
                throw new RuntimeException('同一本机端口已注册其他地址或协议，请部署者核对。');
            }
            if ($found !== null || ! is_string($alias) || ! preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $alias)) {
                throw new RuntimeException('同一本机地址存在多个或无效的目标别名，请部署者核对。');
            }
            $found = $alias;
        }
        if ($found !== null) {
            return $found;
        }
        if (property_exists($targets, 'local')) {
            throw new RuntimeException('local 别名已用于其他设置，请部署者核对。');
        }

        return 'local';
    }

    private static function loopbackAddress(mixed $value): bool
    {
        if (! is_string($value)) {
            return false;
        }
        if (MagpieTargets::isLoopback($value)) {
            return true;
        }
        $packed = @inet_pton($value);

        return $packed !== false && strlen($packed) === 16
            && substr($packed, 0, 12) === str_repeat("\0", 10)."\xff\xff" && ord($packed[12]) === 127;
    }

    /** Locate only complete dotenv assignments; other multiline values may contain assignment-like text. */
    private static function assignmentSpan(string $contents): ?array
    {
        $lines = preg_split('/\r\n|\n|\r/', $contents, flags: PREG_SPLIT_OFFSET_CAPTURE);
        $position = 0;
        $found = null;
        foreach (Lines::process(array_column($lines, 0)) as $entry) {
            $segments = explode("\n", $entry);
            while ($lines[$position][0] !== $segments[0]) {
                $position++;
            }
            $first = $position;
            $position += count($segments);
            if (! array_key_exists(self::ENV_NAME, Dotenv::parse($entry))) {
                continue;
            }
            if ($found !== null) {
                throw new RuntimeException('工作区授权有重复赋值，请先由部署者合并。');
            }
            [$lastLine, $lastOffset] = $lines[$position - 1];
            $offset = $lines[$first][1];
            $found = [$offset, $lastOffset + strlen($lastLine) - $offset];
        }

        return $found;
    }
}
