<?php

namespace App\Http\Middleware;

use App\Services\Api\IdempotencyService;
use App\Support\KnowledgeUploadValidation;
use Closure;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Throwable;

/**
 * 对外 API 写接口幂等中间件（复刻 GEOFlow IdempotencyService）。
 *
 * 挂在 v1 租户隔离路由组上：POST/PATCH 携带 X-Idempotency-Key 的重复请求
 * 且请求体一致时，直接回放首次响应；请求体不一致则 409 冲突。
 */
class EnsureApiIdempotency
{
    public function handle(Request $request, Closure $next): Response
    {
        // Bind the idempotency scope to the concrete resource path. Using the
        // route template here (for example articles/{article}) can replay the
        // response for article 41 when the same client key and body are later
        // used for article 42.
        $routeKey = $request->path();
        $idempotencyKey = IdempotencyService::requestKey($request);
        $recoverableTitleAttempt = $request->isMethod('POST')
            && $request->is('api/v1/materials/titles/generate');
        $recoverableSkillAttempt = $request->isMethod('POST')
            && $routeKey === 'api/v1/skills/execute-async';
        $knowledgeImport = $request->isMethod('POST') && preg_match(
            '#^api/v1/knowledge-bases/[^/]+/(documents|ingest-url|documents/[^/]+/recrawl)$#',
            $routeKey,
        );

        // 未携带幂等键的请求保持原行为，也不为普通 GET/DELETE 请求增加锁开销。
        if ($idempotencyKey === null) {
            if ($knowledgeImport) {
                return response()->json([
                    'message' => '导入材料需要稳定的请求编号，本次未受理。请刷新页面后重试。',
                    'action' => 'idempotency_key_required',
                ], Response::HTTP_CONFLICT);
            }
            if ($request->isMethod('POST') && $routeKey === 'api/v1/skills/execute-async'
                && config('geo.skill_execution_claim_required', true)) {
                return response()->json(['message' => '请先认领当前账号的技能任务，本次未入队。'], 409);
            }
            if ($request->isMethod('POST') && $routeKey === 'api/v1/materials/titles/generate'
                && config('geo.title_generation_claim_required', true)) {
                return response()->json(['message' => '请先认领当前账号的标题请求，本次未发送。'], 409);
            }
            if ($request->isMethod('POST') && $routeKey === 'api/v1/visibility/runs'
                && config('geo.visibility_run_claim_required', true)) {
                return response()->json(['message' => '请先认领当前账号的监测请求，本次未发送。'], 409);
            }
            return $next($request);
        }

        if ($knowledgeImport && str_ends_with($routeKey, '/documents')
            && ($message = KnowledgeUploadValidation::sizeError($request))) {
            return response()->json(['message' => $message, 'errors' => ['file' => [$message]]], 422);
        }

        if ($request->isMethod('POST') && $routeKey === 'api/v1/skills/execute-async'
            && config('geo.skill_execution_claim_required', true)) {
            try {
                $claimed = DB::table('skill_execution_claims')
                    ->where('tenant_id', IdempotencyService::currentTenantId())
                    ->where('user_id', IdempotencyService::currentUserId())
                    ->where('request_hash', IdempotencyService::requestHash($request->all()))
                    ->where('request_key', $idempotencyKey)
                    ->whereNull('released_at')->exists();
            } catch (Throwable $e) {
                report($e);
                return response()->json(['message' => '技能任务认领记录暂不可用，本次未入队。'], 503);
            }
            if (! $claimed) {
                return response()->json(['message' => '请先认领当前账号及本次内容的技能任务，本次未入队。'], 409);
            }
        }

        if ($request->isMethod('POST') && $routeKey === 'api/v1/materials/titles/generate'
            && config('geo.title_generation_claim_required', true)) {
            try {
                $claimed = DB::table('title_generation_claims')
                    ->where('tenant_id', IdempotencyService::currentTenantId())
                    ->where('user_id', IdempotencyService::currentUserId())
                    ->where('request_hash', IdempotencyService::requestHash($request->all()))
                    ->where('request_key', $idempotencyKey)
                    ->whereNull('released_at')->exists();
            } catch (Throwable $e) {
                report($e);
                return response()->json(['message' => '标题请求认领记录暂不可用，本次未发送。'], 503);
            }
            if (! $claimed) {
                return response()->json(['message' => '请先认领当前账号及本次内容的标题请求，本次未发送。'], 409);
            }
        }

        if ($request->isMethod('POST') && $routeKey === 'api/v1/visibility/runs'
            && config('geo.visibility_run_claim_required', true)) {
            try {
                $claimed = DB::table('visibility_run_claims')
                    ->where('tenant_id', IdempotencyService::currentTenantId())
                    ->where('user_id', IdempotencyService::currentUserId())
                    ->where('request_hash', IdempotencyService::requestHash($request->all()))
                    ->where('request_key', $idempotencyKey)
                    ->whereNull('released_at')->exists();
            } catch (Throwable $e) {
                report($e);
                return response()->json(['message' => '监测请求认领记录暂不可用，本次未发送。'], 503);
            }
            if (! $claimed) {
                return response()->json(['message' => '请先认领当前账号及本次内容的监测请求，本次未发送。'], 409);
            }
        }

        // 已完成的重复请求走无锁快速路径。
        $replay = IdempotencyService::maybeReplayJson($request, $routeKey,
            includePending: false, replayFailedResponse: ! $recoverableTitleAttempt);
        if ($replay instanceof JsonResponse) {
            return $replay;
        }

        $tenantId = IdempotencyService::currentTenantId();
        $userId = IdempotencyService::currentUserId();
        $lockSeconds = max(600, (int) config('geo.api_idempotency.lock_seconds', 600));
        $waitSeconds = max(0, (int) config('geo.api_idempotency.wait_seconds', 5));

        try {
            // 生产默认 cache store 是 database，因此不同 Web 容器共享 cache_locks。
            // TTL 高于同步技能 420 秒的进程上限并留出收尾余量；进程崩溃后也会自动解锁。
            $lock = Cache::lock(
                IdempotencyService::lockName($idempotencyKey, $routeKey, $tenantId, $userId),
                $lockSeconds,
            );
            $lock->block($waitSeconds);
        } catch (LockTimeoutException) {
            // 竞争请求等待期间，首请求可能刚好完成。先尝试回放一次，仍未完成才明确告知重试。
            $replay = IdempotencyService::maybeReplayJson($request, $routeKey,
                includePending: false, replayFailedResponse: ! $recoverableTitleAttempt);
            if ($replay instanceof JsonResponse) {
                return $replay;
            }

            return response()->json([
                'message' => '相同请求正在处理中，请稍后使用同一个 X-Idempotency-Key 重试以查看结果。',
                'action' => 'idempotency_request_in_progress',
                'retryable' => true,
                'retry_after_seconds' => 2,
            ], Response::HTTP_CONFLICT, ['Retry-After' => '2']);
        } catch (Throwable $e) {
            // 锁基础设施异常时必须 fail closed；继续执行业务会让付费请求失去防重保护。
            report($e);

            return response()->json([
                'message' => '请求防重保护暂时不可用，请稍后使用同一个 X-Idempotency-Key 重试。',
                'action' => 'idempotency_protection_unavailable',
                'retryable' => true,
                'retry_after_seconds' => 5,
            ], Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => '5']);
        }

        try {
            // 锁外的首次查询与拿锁之间存在时间窗，必须在锁内再查一次。
            // 如果另一请求刚保存了结果，这里直接回放，绝不进入付费业务。
            $replay = IdempotencyService::maybeReplayJson($request, $routeKey,
                includePending: ! $recoverableTitleAttempt,
                replayFailedResponse: ! $recoverableTitleAttempt);
            if ($replay instanceof JsonResponse) {
                return $replay;
            }

            try {
                $reserved = IdempotencyService::reserve(
                    $idempotencyKey,
                    $routeKey,
                    IdempotencyService::hashForRequest($request),
                    $tenantId,
                    $userId,
                    recoverFailedResponse: $recoverableTitleAttempt,
                );
            } catch (ConflictHttpException $e) {
                return response()->json(['message' => $e->getMessage()], Response::HTTP_CONFLICT);
            } catch (Throwable $e) {
                // No durable pending marker means there is no safe way to run a paid action.
                report($e);

                return response()->json([
                    'message' => '请求防重记录暂时不可用，本次未执行，请稍后使用同一个 X-Idempotency-Key 重试。',
                    'action' => 'idempotency_protection_unavailable',
                    'retryable' => true,
                    'retry_after_seconds' => 5,
                ], Response::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => '5']);
            }

            if (! $reserved && ! $recoverableTitleAttempt) {
                // Unique index is the last line of defence if a lock expires or a nonconforming
                // caller races us. Re-read the durable row and never continue into business.
                return IdempotencyService::maybeReplayJson($request, $routeKey)
                    ?? response()->json([
                        'message' => '相同请求已经被接收，请先查看任务列表后再重试。',
                        'action' => 'idempotency_result_pending',
                        'retryable' => true,
                    ], Response::HTTP_CONFLICT, ['Retry-After' => '2']);
            }

            if ($recoverableTitleAttempt) {
                $request->attributes->set('title_first_attempt', $reserved);
            }
            $response = $next($request);

            // These paid routes keep a 5xx as pending. Skill recovery requires
            // the original keyed execution to have started; neither route may
            // enter its paid action again just because the response was lost.
            $pendingRecoveryResponse = ($recoverableTitleAttempt || $recoverableSkillAttempt)
                && $response instanceof JsonResponse
                && $response->getStatusCode() >= Response::HTTP_INTERNAL_SERVER_ERROR;
            if ($response instanceof JsonResponse && ! $pendingRecoveryResponse) {
                // 响应必须在仍持有锁时落库；等待者拿锁后才能看到并回放它。
                IdempotencyService::remember($request, $routeKey, $response, $tenantId, $userId);
            }

            // 目前 v1 写接口均返回 JSON。未来若出现流式/文件等非 JSON 写响应，
            // 预留会有意保持 pending：无法可靠保存并回放的结果不能自动重跑。

            return $response;
        } finally {
            try {
                $lock->release();
            } catch (Throwable $e) {
                // 所有支持原子锁的 store 都会按 TTL 兜底释放；记录异常供运维排查。
                report($e);
            }
        }
    }
}
