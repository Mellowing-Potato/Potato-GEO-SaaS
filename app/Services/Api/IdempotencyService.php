<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted application interfaces, workspace ownership and execution behavior.
 * Upstream: app/Services/Api/IdempotencyService.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\Api;

use App\Casts\EncryptedReplayBody;
use App\Models\ApiIdempotencyKey;
use App\Support\TenantContext;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Symfony\Component\HttpKernel\Exception\UnprocessableEntityHttpException;
use Throwable;

/**
 * 对外 API 写接口幂等缓存服务（复刻 GEOFlow IdempotencyService，增加租户隔离）。
 *
 * 机制：
 * - 客户端在 POST/PATCH 请求携带 X-Idempotency-Key 头；
 * - 同一 (key, route, tenant, user) 的重复请求且请求体一致 → 直接回放首次响应；
 * - 同键但请求体不同 → 返回 409 冲突，避免静默覆盖；
 * - 业务执行前先写 pending 预留，响应写入失败时也不允许同键再次进入业务；
 * - 控制器返回的 JSON 响应无论成功或失败都落库并回放；
 * - 下游抛异常或返回非 JSON 时保留 pending。这是有意的 fail-closed：结果不确定时
 *   宁可要求查看任务记录，也不允许同 key 自动重跑付费业务。
 */
class IdempotencyService
{
    private const TITLE_RECOVERY_MARKER = '{"_recoverable":"title_generation_v2"}';

    /**
     * Build the distributed lock name for one idempotency scope.
     *
     * The request hash is deliberately not part of this name: two requests
     * that reuse a key with different bodies must serialize so the second one
     * can observe (and reject) the conflict instead of both entering business
     * code. Hashing also keeps the database cache_locks primary key bounded
     * when a client sends a long header or the route URI is long.
     */
    public static function lockName(
        string $idempotencyKey,
        string $routeKey,
        ?int $tenantId,
        ?int $userId,
    ): string {
        return 'api-idempotency:'.hash('sha256', self::encodeJson([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'route_key' => $routeKey,
            'idempotency_key' => $idempotencyKey,
        ]));
    }

    /**
     * 递归规范化请求载荷，关联数组按键排序后生成稳定哈希。
     */
    public static function normalizePayload(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if (array_is_list($value)) {
            return array_map([self::class, 'normalizePayload'], $value);
        }

        ksort($value);
        foreach ($value as $key => $item) {
            $value[$key] = self::normalizePayload($item);
        }

        return $value;
    }

    /**
     * 生成请求体哈希（与请求内容绑定，识别同一幂等键是否对应相同请求）。
     *
     * @param  array<string, mixed>  $body
     */
    public static function requestHash(array $body): string
    {
        $normalized = self::normalizePayload($body);

        return hash('sha256', self::encodeJson($normalized));
    }

    /** Bind knowledge imports to the project and actual uploaded bytes. */
    public static function hashForRequest(Request $request): string
    {
        if (! $request->isMethod('POST') || ! preg_match(
            '#^api/v1/knowledge-bases/[^/]+/(?:documents|ingest-url|documents/[^/]+/recrawl)$#D',
            trim($request->path(), '/'),
        )) {
            // Existing paid-request claims use this payload-only protocol.
            return self::requestHash($request->all());
        }

        $attribute = 'yanji.idempotency.request_hash';
        if ($request->attributes->has($attribute)) {
            return $request->attributes->get($attribute);
        }

        $hash = self::requestHash([
            'project_id' => $request->header('X-Project-Id'),
            'payload' => self::knowledgePayload($request->all()),
        ]);
        // The controller may move a temporary upload; remember the original
        // identity before entering business code, including when saving a reply.
        $request->attributes->set($attribute, $hash);

        return $hash;
    }

    private static function knowledgePayload(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            try {
                $path = $value->getRealPath();
                $digest = $value->isValid() && $path !== false ? @hash_file('sha256', $path) : false;
                if (! is_string($digest)) {
                    throw new \RuntimeException('Invalid upload');
                }

                return ['filename' => $value->getClientOriginalName(),
                    'mime' => $value->getClientMimeType(), 'sha256' => $digest];
            } catch (Throwable) {
                throw new UnprocessableEntityHttpException('无法确认上传文件内容，本次未入库，请重新选择文件。');
            }
        }

        return is_array($value) ? array_map(self::knowledgePayload(...), $value) : $value;
    }

    /**
     * 读取已缓存的幂等响应；同键不同请求内容则抛 409 冲突。
     *
     * @return array{state: 'completed', payload: array<string, mixed>, status: int}|array{state: 'pending'}|null
     */
    public static function loadReplay(
        string $idempotencyKey,
        string $routeKey,
        string $requestHash,
        ?int $tenantId,
        ?int $userId,
    ): ?array {
        $row = self::findRow($idempotencyKey, $routeKey, $tenantId, $userId);

        if (! $row) {
            return null;
        }

        if ($row->request_hash !== $requestHash) {
            throw new ConflictHttpException('同一个幂等键对应了不同的请求内容');
        }

        if ($row->response_status === null || $row->response_body === null) {
            return ['state' => 'pending'];
        }

        $decoded = json_decode((string) $row->response_body, true);
        if (! is_array($decoded)) {
            throw new ConflictHttpException('幂等缓存数据损坏');
        }

        if (ApiTokenIssuanceReceipt::matches($routeKey)) {
            $receipt = ApiTokenIssuanceReceipt::withoutSecret($decoded);
            if ($receipt !== $decoded) {
                // Also fail closed for a legacy cache read before the cleanup
                // migration has run. Never replay or keep the old bearer value.
                $row->forceFill(['response_body' => self::encodeJson($receipt)])->save();
            }

            return ['state' => 'completed'] + ApiTokenIssuanceReceipt::replay(
                $receipt, (int) $row->response_status, $tenantId, $userId,
            );
        }

        return [
            'state' => 'completed',
            'status' => (int) $row->response_status,
            'payload' => $decoded,
        ];
    }

    /**
     * Persist an in-progress marker before business code runs.
     *
     * The marker is the durable backstop behind the cache lock: if saving the
     * final response fails after a paid side effect, later requests still see
     * this row and are stopped instead of charging again.
     */
    public static function reserve(
        string $idempotencyKey,
        string $routeKey,
        string $requestHash,
        ?int $tenantId,
        ?int $userId,
        bool $recoverFailedResponse = false,
    ): bool {
        $now = now();
        $inserted = ApiIdempotencyKey::query()->insertOrIgnore([
            'idempotency_key' => $idempotencyKey,
            'route_key' => $routeKey,
            'request_hash' => $requestHash,
            'response_body' => EncryptedReplayBody::encode($recoverFailedResponse ? self::TITLE_RECOVERY_MARKER : null),
            'response_status' => null,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted > 0) {
            return true;
        }

        $row = self::findRow($idempotencyKey, $routeKey, $tenantId, $userId);
        if (! $row) {
            throw new \RuntimeException('无法创建幂等请求预留');
        }
        if ($row->request_hash !== $requestHash) {
            throw new ConflictHttpException('同一个幂等键对应了不同的请求内容');
        }

        if ($recoverFailedResponse) {
            if ($row->response_status === null
                && $row->response_body === self::TITLE_RECOVERY_MARKER) {
                return false;
            }

            $legacyResponse = json_decode((string) $row->response_body, true);
            if ($row->response_status === Response::HTTP_SERVICE_UNAVAILABLE
                && is_array($legacyResponse)
                && ($legacyResponse['action'] ?? null) === 'idempotency_result_pending') {
                // The old title controller returned this response before any
                // title insert. Other legacy pending/error rows may already
                // have delivered titles and must be reviewed manually.
                $row->forceFill([
                    'response_body' => self::TITLE_RECOVERY_MARKER,
                    'response_status' => null,
                ])->save();

                return false;
            }

            throw new ConflictHttpException('历史标题请求结果未决，请核对原请求后再处理。');
        }

        return false;
    }

    /**
     * 首次保存幂等响应缓存；并发写入时若已存在相同请求则保留原响应，内容冲突则 409。
     *
     * @param  array<string, mixed>  $payload
     */
    public static function store(
        string $idempotencyKey,
        string $routeKey,
        string $requestHash,
        array $payload,
        int $status,
        ?int $tenantId,
        ?int $userId,
    ): void {
        $encoded = self::encodeJson(ApiTokenIssuanceReceipt::matches($routeKey)
            ? ApiTokenIssuanceReceipt::withoutSecret($payload) : $payload);
        $row = self::findRow($idempotencyKey, $routeKey, $tenantId, $userId);

        if ($row) {
            if ($row->request_hash !== $requestHash) {
                throw new ConflictHttpException('同一个幂等键对应了不同的请求内容');
            }

            // A completed response is immutable. This matters if a caller uses
            // store() directly outside the middleware or a lock expires late.
            if ($row->response_status !== null && $row->response_body !== null) {
                return;
            }

            $row->forceFill([
                'response_body' => $encoded,
                'response_status' => $status,
            ])->save();

            return;
        }

        $now = now();
        $inserted = ApiIdempotencyKey::query()->insertOrIgnore([
            'idempotency_key' => $idempotencyKey,
            'route_key' => $routeKey,
            'request_hash' => $requestHash,
            'response_body' => EncryptedReplayBody::encode($encoded),
            'response_status' => $status,
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted > 0) {
            return;
        }

        $row = self::findRow($idempotencyKey, $routeKey, $tenantId, $userId);

        if ($row?->request_hash !== $requestHash) {
            throw new ConflictHttpException('同一个幂等键对应了不同的请求内容');
        }

        if ($row && ($row->response_status === null || $row->response_body === null)) {
            $row->forceFill([
                'response_body' => $encoded,
                'response_status' => $status,
            ])->save();
        }
    }

    /**
     * 若当前请求命中幂等缓存，返回缓存的 JSON 响应；否则返回 null。
     */
    public static function maybeReplayJson(
        Request $request,
        string $routeKey,
        bool $includePending = true,
        bool $replayFailedResponse = true,
    ): ?JsonResponse {
        $key = self::requestKey($request);
        if ($key === null) {
            return null;
        }

        $hash = self::hashForRequest($request);

        try {
            $replay = self::loadReplay($key, $routeKey, $hash, self::tenantId(), self::userId());
        } catch (ConflictHttpException $e) {
            return response()->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT);
        }

        if ($replay === null) {
            return null;
        }

        if ($replay['state'] === 'pending') {
            if (! $includePending) {
                return null;
            }

            return response()->json([
                'message' => '相同请求已经被接收，结果仍在生成或尚未保存。系统不会重复执行；请先查看任务列表，并稍后使用同一个 X-Idempotency-Key 重试。',
                'action' => 'idempotency_result_pending',
                'retryable' => true,
                'retry_after_seconds' => 2,
            ], Response::HTTP_CONFLICT, ['Retry-After' => '2']);
        }

        if (! $replayFailedResponse
            && $replay['status'] === Response::HTTP_SERVICE_UNAVAILABLE
            && ($replay['payload']['action'] ?? null) === 'idempotency_result_pending') {
            return null;
        }

        return response()->json($replay['payload'], $replay['status']);
    }

    /**
     * 在响应确定后完成幂等记录。错误 JSON 也必须回放，避免一个已产生副作用的
     * 5xx/4xx 因客户端自动重试而再次进入业务。
     */
    public static function remember(
        Request $request,
        string $routeKey,
        JsonResponse $response,
        ?int $tenantId,
        ?int $userId,
    ): void
    {
        $key = self::requestKey($request);
        if ($key === null) {
            return;
        }

        $decoded = json_decode($response->getContent(), true);
        if (! is_array($decoded)) {
            return;
        }

        try {
            // A synchronous job can clear the mutable TenantContext while the
            // controller runs. Persist under the owner captured before dispatch.
            self::store($key, $routeKey, self::hashForRequest($request), $decoded,
                $response->getStatusCode(), $tenantId, $userId);
        } catch (Throwable $e) {
            // pending 预留仍在，因此即便最终响应落库失败，重复请求也不会再次进入业务。
            report($e);
        }
    }

    /**
     * Return the caller supplied key when this request opts into idempotency.
     */
    public static function requestKey(Request $request): ?string
    {
        if (! in_array($request->method(), ['POST', 'PATCH'], true)) {
            return null;
        }

        $key = $request->header('X-Idempotency-Key');

        return is_string($key) && $key !== '' ? $key : null;
    }

    public static function currentTenantId(): ?int
    {
        return self::tenantId();
    }

    public static function currentUserId(): ?int
    {
        return self::userId();
    }

    private static function tenantId(): ?int
    {
        return app(TenantContext::class)->has()
            ? (int) app(TenantContext::class)->id()
            : null;
    }

    private static function userId(): ?int
    {
        return auth()->check() ? (int) auth()->id() : null;
    }

    private static function findRow(
        string $idempotencyKey,
        string $routeKey,
        ?int $tenantId,
        ?int $userId,
    ): ?ApiIdempotencyKey {
        return ApiIdempotencyKey::query()
            ->where('idempotency_key', $idempotencyKey)
            ->where('route_key', $routeKey)
            ->where('tenant_id', $tenantId)
            ->where('user_id', $userId)
            ->first();
    }

    private static function encodeJson(mixed $value): string
    {
        try {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return '{}';
        }
    }
}
