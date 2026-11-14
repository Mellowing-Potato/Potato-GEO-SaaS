<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Support/AdminActivityLogger.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\Admin;

use App\Models\AdminActivityLog;
use Illuminate\Http\Request;
use Throwable;

/**
 * 平台/租户关键操作活动日志（阶段 3.4，对标 GEOFlow AdminActivityLogger）。
 *
 * 统一脱敏密码/密钥、对超长内容仅记录长度摘要，避免审计表膨胀或泄露敏感信息。
 * 写入失败不影响主流程（审计是旁路）。
 */
final class ActivityLogService
{
    /**
     * @param  array{
     *   tenant_id?: int|null,
     *   actor_id?: int|null,
     *   actor_type?: string,
     *   actor_name?: string|null,
     *   request_method?: string,
     *   page?: string|null,
     *   target_type?: string|null,
     *   target_id?: int|null,
     *   ip_address?: string,
     *   details?: array<string,mixed>|string|null,
     * }  $context
     */
    public function record(string $action, array $context = []): ?AdminActivityLog
    {
        $details = $context['details'] ?? null;
        if (is_array($details)) {
            $details = json_encode($this->sanitize($details), JSON_UNESCAPED_UNICODE);
        } elseif (is_string($details)) {
            $details = mb_substr($details, 0, 1000);
        } else {
            $details = null;
        }

        try {
            return AdminActivityLog::query()->create([
                'tenant_id' => $context['tenant_id'] ?? null,
                'actor_id' => $context['actor_id'] ?? null,
                'actor_type' => $context['actor_type'] ?? 'user',
                'actor_name' => $context['actor_name'] ?? null,
                'action' => mb_substr(trim($action), 0, 80),
                'request_method' => strtoupper((string) ($context['request_method'] ?? 'GET')),
                'page' => $context['page'] ?? null,
                'target_type' => $context['target_type'] ?? null,
                'target_id' => $context['target_id'] ?? null,
                'ip_address' => (string) ($context['ip_address'] ?? ''),
                'details' => $details,
                'created_at' => now(),
            ]);
        } catch (Throwable) {
            // 审计写入失败绝不影响主流程。
            return null;
        }
    }

    /**
     * 从请求推导上下文并写入（供中间件调用）。
     *
     * @param  array<string,mixed>  $details
     */
    public function recordFromRequest(Request $request, string $action, array $details = []): ?AdminActivityLog
    {
        [$targetType, $targetId] = $this->guessTarget($request);
        $user = $request->user();

        return $this->record($action, [
            'actor_id' => $user?->id,
            'actor_type' => 'user',
            'actor_name' => $user?->name ?? $user?->email,
            'request_method' => (string) $request->method(),
            'page' => $this->resolvePage($request),
            'target_type' => $targetType,
            'target_id' => $targetId,
            'ip_address' => (string) ($request->ip() ?? ''),
            'details' => $details,
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function sanitize(array $payload): array
    {
        $sensitive = ['password', 'package_password', 'current_password', 'new_password', 'confirm_password', 'api_key', '_token', 'csrf_token', 'secret', 'token'];

        $result = [];
        foreach ($payload as $key => $value) {
            $field = (string) $key;
            if (in_array($field, $sensitive, true)) {
                $result[$field] = '[redacted]';

                continue;
            }
            if (is_array($value)) {
                $result[$field] = $this->sanitize($value);

                continue;
            }
            if (is_bool($value) || $value === null) {
                $result[$field] = $value;

                continue;
            }
            $text = trim((string) $value);
            if ($text === '') {
                $result[$field] = '';

                continue;
            }
            if (preg_match('/content|prompt|description|bio|note|words|html/i', $field) === 1) {
                $result[$field] = '[text:'.mb_strlen($text).' chars]';

                continue;
            }
            if (mb_strlen($text) > 180) {
                $text = mb_substr($text, 0, 180).'...';
            }
            $result[$field] = $text;
        }

        return $result;
    }

    /**
     * @return array{0:string,1:int|null}
     */
    private function guessTarget(Request $request): array
    {
        $route = $request->route();
        if (! $route) {
            return ['', null];
        }

        foreach ((array) $route->parameters() as $name => $value) {
            if (! str_ends_with((string) $name, 'Id')) {
                continue;
            }
            $targetType = substr((string) $name, 0, -2);
            $targetId = is_numeric($value) ? (int) $value : null;

            return [$targetType, $targetId];
        }

        return ['', null];
    }

    private function resolvePage(Request $request): string
    {
        $path = trim((string) $request->path(), '/');
        if ($path === '') {
            return '';
        }
        $segments = explode('/', $path);

        return (string) end($segments);
    }
}
