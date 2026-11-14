<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ArticlePublication;
use App\Models\DistributionChannelSecret;
use App\Models\PublishingChannel;
use App\Models\Tenant;
use App\Services\Reports\AnalyticsIngestionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * X3.1 采集二期：外站分发内容回传信标（公开入站端点，无 session/Sanctum）。
 *
 * 认证复用 GEOFlow 签名协议（与 Agent 包 VerifyGeoFlowSignature 同构）：
 *   canonical = METHOD\nPATH\nTIMESTAMP\nNONCE\nBODY_HASH
 *   HMAC-SHA256(canonical, 密钥) → X-GEOFlow-Signature
 * 密钥按 X-GEOFlow-Key-Id 反查 DistributionChannelSecret（多租户），
 * 查不到 → 401 signature_error（不泄露 key 是否存在）。
 * nonce 防重放（Agent 包原协议签了 nonce 但从没查重，这里必须查）。
 */
final class GeoFlowBeaconController extends Controller
{
    private const MAX_SKEW = 300;
    private const NONCE_TTL = 600;
    private const PER_KEY_LIMIT = 60;
    private const BATCH_LIMIT = 50;

    public function __construct(
        private readonly AnalyticsIngestionService $ingestion,
    ) {}

    public function visits(Request $request): JsonResponse
    {
        $keyId = $request->header('X-GEOFlow-Key-Id');
        $timestamp = $request->header('X-GEOFlow-Timestamp');
        $nonce = $request->header('X-GEOFlow-Nonce');
        $bodyHash = $request->header('X-GEOFlow-Body-SHA256');
        $signature = $request->header('X-GEOFlow-Signature');

        if (! $keyId || ! $timestamp || ! $nonce || ! $bodyHash || ! $signature) {
            return $this->sigError();
        }

        $body = $request->getContent();

        if (! hash_equals(hash('sha256', $body), (string) $bodyHash)) {
            return $this->sigError();
        }

        if (abs(time() - (int) $timestamp) > self::MAX_SKEW) {
            return $this->sigError();
        }

        /** @var DistributionChannelSecret|null $secret */
        $secret = DistributionChannelSecret::query()
            ->where('key_id', $keyId)
            ->where('status', 'active')
            ->first();

        if (! $secret) {
            // 不泄露 key 是否存在：统一 signature_error；差异原因仅入日志
            Log::info('GeoFlow beacon: unknown/inactive key_id probed', ['key_id' => $keyId]);
            return $this->sigError();
        }

        // 速率限制（per key_id 60 次/分钟）：命中即计数，先于解密/HMAC（S3），
        // 失败成功都算次数，避免攻击者无限次爆破每次白耗一次 DB 查询 + decrypt + HMAC。
        $rateKey = "geoflow-beacon:{$keyId}";
        if (RateLimiter::tooManyAttempts($rateKey, self::PER_KEY_LIMIT)) {
            return response()->json([
                'error' => 'rate_limited',
                'message' => 'Too many requests.',
            ], 429);
        }
        RateLimiter::hit($rateKey, 60);

        // 校验签名
        $path = parse_url($request->fullUrl(), PHP_URL_PATH) ?: '/';
        $canonical = implode("\n", [
            strtoupper($request->method()),
            $path,
            $timestamp,
            $nonce,
            $bodyHash,
        ]);
        $expected = hash_hmac('sha256', $canonical, $secret->decryptSecret());

        if (! hash_equals($expected, (string) $signature)) {
            // 不泄露 key 是否存在，统一 signature_error；签名错仅入日志
            Log::info('GeoFlow beacon: signature mismatch', ['key_id' => $keyId]);
            return $this->sigError();
        }

        // nonce 防重放：必须在签名校验通过之后检查（S2），
        // 否则未认证请求可灌爆缓存 / 烧掉合法 nonce。
        $nonceKey = "geoflow-beacon-nonce:{$keyId}:{$nonce}";
        if (! Cache::add($nonceKey, 1, self::NONCE_TTL)) {
            Log::info('GeoFlow beacon: nonce replay', ['key_id' => $keyId]);
            return $this->sigError();
        }

        /** @var PublishingChannel|null $channel */
        $channel = $secret->channel;
        if (! $channel) {
            return $this->sigError();
        }
        $tenantId = (int) $channel->tenant_id;

        // 触碰密钥最后使用时间
        try {
            $secret->markUsed();
        } catch (Throwable $e) {
            Log::warning('GeoFlow beacon: markUsed failed', ['error' => $e->getMessage()]);
        }

        // 解析批量体
        $payload = json_decode($body, true);
        if (! is_array($payload) || ! isset($payload['visits']) || ! is_array($payload['visits'])) {
            return response()->json(['error' => 'invalid_payload', 'message' => 'Missing visits array.'], 422);
        }

        $visits = $payload['visits'];
        if (count($visits) > self::BATCH_LIMIT) {
            return response()->json([
                'error' => 'batch_too_large',
                'message' => 'Max ' . self::BATCH_LIMIT . ' visits per batch.',
            ], 422);
        }

        $accepted = 0;
        $dropped = 0;
        $sevenDaysAgo = now()->subDays(7);

        foreach ($visits as $visit) {
            if (! is_array($visit) || ! $this->validateVisit($visit)) {
                $dropped++;
                continue;
            }

            // occurred_at 早于 7 天前的丢弃（不入库）
            $occurredAt = $visit['occurred_at'] ?? null;
            if ($occurredAt) {
                try {
                    $dt = new \DateTimeImmutable((string) $occurredAt);
                    if ($dt < $sevenDaysAgo) {
                        $dropped++;
                        continue;
                    }
                } catch (Throwable) {
                    $dropped++;
                    continue;
                }
            }

            $articleId = $this->resolveArticleId($channel, (string) ($visit['path'] ?? ''));

            try {
                $this->ingestion->recordVisit([
                    'tenant_id' => $tenantId,
                    'project_id' => null,
                    'article_id' => $articleId,
                    'publishing_channel_id' => (int) $channel->id,
                    // 不收也不信 client 报的 IP；记请求来源 IP（客户站服务器出口）
                    'ip_address' => (string) $request->ip(),
                    'user_agent' => $visit['user_agent'] ?? null,
                    'referer' => $visit['referer'] ?? null,
                    'path' => $visit['path'] ?? null,
                    'method' => $visit['method'] ?? null,
                    'status_code' => isset($visit['status_code']) ? (int) $visit['status_code'] : null,
                ]);
                $accepted++;
            } catch (Throwable $e) {
                // 单条失败不拖垮整批
                Log::warning('GeoFlow beacon: visit record failed', [
                    'channel_id' => $channel->id,
                    'error' => $e->getMessage(),
                ]);
                $dropped++;
            }
        }

        return response()->json([
            'accepted' => $accepted,
            'dropped' => $dropped,
        ]);
    }

    private function validateVisit(array $visit): bool
    {
        $path = $visit['path'] ?? null;
        if (! is_string($path) || $path === '' || mb_strlen($path) > 512) {
            return false;
        }
        if (isset($visit['user_agent']) && (! is_string($visit['user_agent']) || mb_strlen($visit['user_agent']) > 1000)) {
            return false;
        }
        if (isset($visit['referer']) && (! is_string($visit['referer']) || mb_strlen($visit['referer']) > 1000)) {
            return false;
        }
        if (isset($visit['method']) && ! in_array(strtoupper((string) $visit['method']), ['GET', 'HEAD', 'POST'], true)) {
            return false;
        }
        if (isset($visit['status_code'])) {
            $code = (int) $visit['status_code'];
            if ($code < 100 || $code > 599) {
                return false;
            }
        }
        return true;
    }

    /**
     * 文章反查：remote_id 出现在 path 中，或 remote_url 的 path 与上报 path 相等。
     * 匹配不到 → 返回 null（爬虫扫站也是有效信号，仍入库 article_id=null）。
     */
    private function resolveArticleId(PublishingChannel $channel, string $path): ?int
    {
        $basename = basename(rtrim($path, '/'));
        if ($basename === '') {
            return null;
        }

        /** @var ArticlePublication|null $pub */
        $pub = ArticlePublication::query()
            ->where('publishing_channel_id', $channel->id)
            ->where(function ($q) use ($path, $basename) {
                $q->where('remote_id', $basename)
                    ->orWhere('remote_url', $path)
                    ->orWhere('remote_url', 'like', '%/' . $basename);
            })
            ->first();

        return $pub?->article_id;
    }

    private function sigError(): JsonResponse
    {
        return response()->json([
            'error' => 'signature_error',
            'message' => 'Signature verification failed.',
        ], 401);
    }
}
