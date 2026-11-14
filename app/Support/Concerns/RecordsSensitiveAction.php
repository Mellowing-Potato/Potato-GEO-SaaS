<?php

namespace App\Support\Concerns;

use App\Services\Admin\ActivityLogService;

/**
 * 敏感操作审计（计划 §9.7）。
 *
 * 背景：`LogAdminActivity` 中间件只覆盖 `api/v1/platform/*` 的写请求；租户侧的敏感动作
 * （删数据、发/撤密钥）过去散落在各控制器里，有的写审计、有的不写 —— 事后要回答
 * "这条渠道密钥是谁在什么时候生成的"就成了靠猜。这层把口径统一成一句话：
 *
 *   **凡是"改权限、发/撤凭据、动钱、删数据、对外发布"的动作，都必须落一行审计。**
 *
 * 用法（控制器里一行）：
 *   $this->auditSensitive('channel_secret.created', $channel, ['name' => $channel->name]);
 *
 * 红线：审计是**旁路** —— 写入失败绝不影响主流程（ActivityLogService 内部已 catch）；
 * 明细里**不许放凭据本身**（明文密钥/令牌绝不进审计，只记 id 与名称）。
 */
trait RecordsSensitiveAction
{
    /**
     * @param  array<string,mixed>  $details  只放"能公开的辨识信息"（名称/id/变更点），不放凭据
     */
    protected function auditSensitive(string $action, ?object $target = null, array $details = []): void
    {
        try {
            /** @var ActivityLogService $log */
            $log = app(ActivityLogService::class);
            $request = app()->runningInConsole() ? null : request();
            $user = $request?->user();

            $log->record($action, [
                'tenant_id' => $target?->tenant_id ?? $user?->tenant_id,
                'actor_id' => $user?->id,
                'actor_type' => 'user',
                'actor_name' => $user?->name ?? $user?->email,
                'request_method' => $request ? strtoupper((string) $request->method()) : 'CLI',
                'page' => $request ? (string) last(explode('/', trim((string) $request->path(), '/'))) : null,
                'target_type' => $target ? class_basename($target) : null,
                'target_id' => $target?->id,
                'ip_address' => $request ? (string) $request->ip() : '',
                'details' => $details,
            ]);
        } catch (\Throwable) {
            // 审计失败不影响主流程（与 ActivityLogService 的口径一致，双保险）。
        }
    }
}
