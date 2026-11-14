<?php

namespace App\Jobs;

use App\Models\LeadForm;
use App\Models\LeadSubmission;
use App\Models\Tenant;
use App\Services\Outbound\OutboundRequestBlockedException;
use App\Services\Outbound\OutboundRequestFailedException;
use App\Services\Outbound\SafeOutboundHttpClient;
use App\Services\Outbound\SecureHttpFactory;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * 新线索通知投递（单 8 收尾）。
 *
 * 为什么是 webhook 而不是邮件/短信：生产实测两条路都是死的 —— geo-saas 的
 * MAIL_MAILER=log（这个服务从没发出过一封邮件），四类通知短信老板已拍板搁置。
 * webhook 是当下唯一能把消息真正推到人眼前的通道，而且租户填一个企业微信 /
 * 钉钉群机器人地址就能用，不必自己写接收端。
 *
 * ⚠️ 队列必须是 publishing：生产 geo-worker 的命令行写死了监听队列
 *    `publishing,distribution,skills,tasks,url-import,knowledge,billing`，
 *    派到这七个之外的队列名 = 任务永远躺在 jobs 表里没人跑，且不报任何错。
 *
 * ⚠️ 出站必须走 SafeOutboundHttpClient：地址是租户自己填的，等于把 SSRF 面
 *    直接交到租户手上。该客户端封掉全部私网段 / 链路本地 / DNS 重绑定 / 跳转逃逸。
 *
 * ⚠️ 日志纪律：线索内容是客户 PII（姓名、电话），webhook 地址本身是凭据
 *    （机器人 URL 里带 key）。两者一律不准进日志 —— 本仓吃过亏，
 *    网关那次把整封邮件 HTML 含收件人邮箱写进了日志。这里只记 host 和原因码。
 */
class DeliverLeadNotificationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** 单次外呼上限；机器人接口通常秒级返回 */
    public int $timeout = 30;

    /** 通知失败可以重试（与发布不同：这里没有「重复发布」的副作用，最多群里多一条） */
    public int $tries = 3;

    /** 退避：10s / 30s，避开对端瞬时限流 */
    public array $backoff = [10, 30];

    /** 响应体只用来判成败，不需要大 */
    private const MAX_RESPONSE_BYTES = 64 * 1024;

    /** 企业微信 text 正文上限 2048 字节，留余量 */
    private const MAX_CONTENT_CHARS = 600;

    public function __construct(
        public readonly int $submissionId,
        public readonly int $tenantId,
    ) {
        $this->onQueue('publishing');
    }

    public function handle(SafeOutboundHttpClient $outbound, SecureHttpFactory $http): void
    {
        // ─── 1. 还原租户上下文（队列 worker 里没有请求上下文）───
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            Log::error('LeadNotify: tenant not found.', [
                'submission_id' => $this->submissionId,
                'tenant_id' => $this->tenantId,
            ]);

            return;
        }

        TenantContext::set($tenant);

        try {
            // ─── 2. 取线索与表单，显式按 tenant_id 过滤，不靠全局作用域 ───
            $submission = LeadSubmission::query()
                ->where('tenant_id', $this->tenantId)
                ->whereKey($this->submissionId)
                ->first();

            if (! $submission) {
                Log::warning('LeadNotify: submission not found.', [
                    'submission_id' => $this->submissionId,
                    'tenant_id' => $this->tenantId,
                ]);

                return;
            }

            $form = LeadForm::query()
                ->where('tenant_id', $this->tenantId)
                ->whereKey($submission->lead_form_id)
                ->first();

            // 入队之后租户可能把地址清空了 —— 那就按「不需要通知」收尾，不算失败。
            if (! $form instanceof LeadForm || ! $form->notifiesOnSubmission()) {
                $this->finish($submission, LeadSubmission::NOTIFY_SKIPPED);

                return;
            }

            $url = trim((string) $form->notify_webhook_url);
            $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');

            // ─── 3. 组报文 ───
            $body = $form->notify_webhook_format === LeadForm::NOTIFY_FORMAT_JSON
                ? $this->jsonPayload($tenant, $form, $submission)
                : $this->robotPayload($form, $submission);

            // ⚠️ 必须用 SecureHttpFactory，不能用 Http facade。
            // OutboundServiceProvider 是**定向绑定**：Http facade 背后仍是 Laravel 原生
            // Factory，造出来的是普通 PendingRequest；而 LaravelPinnedOutboundTransport
            // 只收 SecurePendingRequest，拿到普通的直接抛 secure_pending_request_required。
            // 用错工厂 = 通知 100% 发不出去，且报错文案会误导成「地址是内网」。
            // 09-01 生产实测踩过：webhook.site 被判「不允许指向内网地址」，真因就是这里。
            $request = $http->asJson()
                ->timeout(15)
                ->connectTimeout(10)
                ->withHeaders(['Content-Type' => 'application/json']);

            // ─── 4. 出站 ───
            $response = $outbound->post($request, $url, $body, self::MAX_RESPONSE_BYTES);

            if ($response->successful()) {
                $this->finish($submission, LeadSubmission::NOTIFY_SENT);

                return;
            }

            // 4xx 基本是地址写错/机器人被删，重试没有意义，直接落终态。
            // 5xx 抛出去交给队列重试。
            if ($response->status() >= 400 && $response->status() < 500) {
                Log::warning('LeadNotify: rejected by endpoint.', [
                    'submission_id' => $submission->id,
                    'tenant_id' => $this->tenantId,
                    'endpoint_host' => $host,
                    'status' => $response->status(),
                ]);
                $this->finish($submission, LeadSubmission::NOTIFY_FAILED, '对方拒收（HTTP '.$response->status().'），请检查通知地址是否有效');

                return;
            }

            throw new \RuntimeException('notify_upstream_'.$response->status());
        } catch (OutboundRequestBlockedException $e) {
            // [outbound-blocked] 是本仓约定的告警锚点，沿用同一前缀便于检索。
            Log::warning('[outbound-blocked] LeadNotify: blocked by outbound policy.', [
                'submission_id' => $this->submissionId,
                'tenant_id' => $this->tenantId,
                'reason_code' => $e->reasonCode,
                'endpoint_host' => $host ?? null,
            ]);
            // 被安全策略拦下重试同样会被拦 —— 直接落终态。
            if (isset($submission)) {
                $this->finish($submission, LeadSubmission::NOTIFY_FAILED, $this->blockedMessage($e->reasonCode));
            }
        } catch (OutboundRequestFailedException|ConnectionException $e) {
            // 连接层失败：交给队列重试，最终失败由 failed() 收尾。
            Log::warning('LeadNotify: transport failure.', [
                'submission_id' => $this->submissionId,
                'tenant_id' => $this->tenantId,
                'endpoint_host' => $host ?? null,
            ]);

            throw $e;
        } finally {
            TenantContext::forget();
        }
    }

    /**
     * 三次都失败后的终态收尾。队列在这里调用时已不在 handle 的 try 内，需自行还原上下文。
     */
    public function failed(?\Throwable $e): void
    {
        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        TenantContext::set($tenant);

        try {
            $submission = LeadSubmission::query()
                ->where('tenant_id', $this->tenantId)
                ->whereKey($this->submissionId)
                ->first();

            if ($submission instanceof LeadSubmission) {
                $this->finish($submission, LeadSubmission::NOTIFY_FAILED, '通知发送失败，已重试 '.$this->tries.' 次');
            }
        } finally {
            TenantContext::forget();
        }
    }

    /**
     * 出站被拦时给租户看的原因。
     *
     * ⚠️ 照 UrlImportProcessingService / DocumentIngestionService 同一套口径：
     * 只有地址类原因码才能说「地址有问题」。pinning_unavailable、
     * secure_pending_request_required 这些是本系统内部的装配问题，
     * 跟租户填的地址无关，不许赖到租户头上 —— 09-01 就是这句话把
     * 「我们自己用错了 HTTP 工厂」说成了「你填的是内网地址」，
     * 排查时先去查 webhook.site 通不通，绕了一圈。
     */
    private function blockedMessage(string $reasonCode): string
    {
        return match ($reasonCode) {
            'unsafe_address', 'mapped_address', 'ambiguous_ip' => '通知地址指向内网或其他非公网可达地址，出于安全考虑不能发送。',
            'dns_resolution_failed' => '通知地址的域名解析不到，请确认它在公网上可以访问。',
            'invalid_url', 'invalid_scheme', 'control_character', 'invalid_host',
            'invalid_port', 'invalid_address', 'userinfo_forbidden',
            'fragment_forbidden' => '通知地址格式不对，请检查后重填。',
            default => '通知发送失败：这次请求没能通过出站安全检查，请稍后再试；如持续出现请联系客服。',
        };
    }

    private function finish(LeadSubmission $submission, string $status, ?string $error = null): void
    {
        $submission->forceFill([
            'notify_status' => $status,
            'notify_error' => $error === null ? null : mb_substr($error, 0, 200),
            'notified_at' => now(),
        ])->save();
    }

    /**
     * 企业微信 / 钉钉群机器人报文。两家的文本消息形状恰好一致，同一份报文两边都认。
     *
     * @return array<string, mixed>
     */
    private function robotPayload(LeadForm $form, LeadSubmission $submission): array
    {
        $lines = ['【新线索】'.$form->name];

        foreach ($this->readableFields($form, $submission) as $label => $value) {
            $lines[] = $label.'：'.$value;
        }

        $lines[] = '提交时间：'.optional($submission->created_at)->format('Y-m-d H:i');

        $content = mb_substr(implode("\n", $lines), 0, self::MAX_CONTENT_CHARS);

        return [
            'msgtype' => 'text',
            'text' => ['content' => $content],
        ];
    }

    /**
     * 自建接收端用的结构化报文。
     *
     * 不把两种形状合成一份发出去：群机器人对多余的顶层字段是否宽容没有文档保证，
     * 猜错就是通知全线不通。分两种形态，各自照对方的契约发。
     *
     * @return array<string, mixed>
     */
    private function jsonPayload(Tenant $tenant, LeadForm $form, LeadSubmission $submission): array
    {
        return [
            'event' => 'lead.created',
            'tenant' => ['id' => $tenant->id, 'subdomain' => $tenant->subdomain],
            'form' => ['id' => $form->id, 'name' => $form->name, 'slug' => $form->slug],
            'submission' => [
                'id' => $submission->id,
                'fields' => $this->readableFields($form, $submission),
                'source_url' => $submission->source_url,
                'created_at' => optional($submission->created_at)->toIso8601String(),
            ],
        ];
    }

    /**
     * 把 payload 的键换成表单里配置的中文标签，顺序按表单字段声明。
     *
     * @return array<string, string>
     */
    private function readableFields(LeadForm $form, LeadSubmission $submission): array
    {
        $payload = is_array($submission->payload) ? $submission->payload : [];
        $labels = [];

        foreach ($form->normalizedFields() as $field) {
            $name = (string) ($field['name'] ?? '');
            if ($name !== '') {
                $labels[$name] = (string) ($field['label'] ?? $name);
            }
        }

        $out = [];
        foreach ($payload as $key => $value) {
            $label = $labels[$key] ?? (string) $key;
            $out[$label] = is_array($value) ? implode('、', array_map('strval', $value)) : (string) $value;
        }

        return $out;
    }
}
