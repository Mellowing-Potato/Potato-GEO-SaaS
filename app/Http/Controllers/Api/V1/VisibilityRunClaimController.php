<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AiVisibilityRun;
use App\Models\ApiIdempotencyKey;
use App\Services\Api\IdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A durable, account-bound key for one visibility batch before the paid POST. */
class VisibilityRunClaimController extends Controller
{
    private const ROUTE = 'api/v1/visibility/runs';

    public function claim(Request $request): JsonResponse
    {
        abort_unless(config('geo.visibility_panel_enabled'), 404);
        $validated = $request->validate([
            'payload' => ['required', 'array'],
            'payload.project_id' => ['required', 'integer', 'min:1'],
            'payload.questions' => ['required', 'array', 'min:1', 'max:'.max(1, (int) config('geo.visibility.max_questions', 10))],
            'payload.questions.*' => ['required', 'string', 'max:500'],
            'request_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_.:-]{1,160}$/'],
        ]);
        $tenantId = IdempotencyService::currentTenantId();
        $userId = (int) $request->user()->id;
        $hash = IdempotencyService::requestHash($validated['payload']);
        $candidate = $validated['request_key'] ?? 'visibility-run-'.bin2hex(random_bytes(24));

        $claim = DB::transaction(function () use ($tenantId, $userId, $hash, $candidate): array {
            DB::table('visibility_run_claims')->insertOrIgnore([
                'tenant_id' => $tenantId, 'user_id' => $userId,
                'request_hash' => $hash, 'request_key' => $candidate,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('visibility_run_claims')
                ->where('tenant_id', $tenantId)->where('user_id', $userId)
                ->where('request_hash', $hash)->lockForUpdate()->first();

            // Legacy clients may have reached the paid route before claims existed.
            // Recover one exact owner/body match; multiple matches need review.
            $unseen = ApiIdempotencyKey::query()
                ->where('tenant_id', $tenantId)->where('user_id', $userId)
                ->where('route_key', self::ROUTE)->where('request_hash', $hash)
                ->whereNull('client_seen_at')
                ->where(function ($query): void {
                    $query->whereNull('response_status')
                        ->orWhereNotIn('response_status', [402, 422]);
                })->orderBy('id')->limit(2)->get();
            if ($unseen->count() > 1) {
                return ['conflict' => true];
            }
            $oldClaimResult = ApiIdempotencyKey::query()->where('tenant_id', $tenantId)
                ->where('user_id', $userId)->where('route_key', self::ROUTE)
                ->where('idempotency_key', $row->request_key)->first();
            $safeUnsent = in_array($oldClaimResult?->response_status, [402, 422], true);
            $key = $unseen->first()?->idempotency_key
                ?? ($row->released_at === null && ! $safeUnsent ? $row->request_key : $candidate);
            if ($row->request_key !== $key || $row->released_at !== null) {
                DB::table('visibility_run_claims')->where('id', $row->id)
                    ->update(['request_key' => $key, 'released_at' => null, 'updated_at' => now()]);
            }

            return ['request_key' => $key,
                'accepted' => $unseen->isNotEmpty()];
        });

        if (isset($claim['conflict'])) {
            return response()->json(['message' => '同内容的历史监测请求不止一笔，请先核对任务列表，本次未发送。'], 409);
        }

        return response()->json(['object' => 'visibility_run_claim', 'ok' => true] + $claim);
    }

    public function seen(Request $request): JsonResponse
    {
        abort_unless(config('geo.visibility_panel_enabled'), 404);
        $validated = $request->validate([
            'request_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_.:-]{1,160}$/'],
        ]);
        $tenantId = IdempotencyService::currentTenantId();
        $userId = (int) $request->user()->id;
        $row = ApiIdempotencyKey::query()->where('tenant_id', $tenantId)
            ->where('user_id', $userId)->where('route_key', self::ROUTE)
            ->where('idempotency_key', $validated['request_key'])->first();
        $body = json_decode((string) $row?->response_body, true);
        $batchId = is_array($body) ? ($body['batch_id'] ?? null) : null;
        if (! $row || $row->response_status !== 202 || ! is_string($batchId)
            || ! AiVisibilityRun::query()->where('batch_id', $batchId)
                ->where('user_id', $userId)->exists()) {
            return response()->json(['message' => '原监测批次尚未核实，不能确认送达。'], 409);
        }
        DB::transaction(function () use ($tenantId, $userId, $row): void {
            ApiIdempotencyKey::query()->whereKey($row->id)->whereNull('client_seen_at')
                ->update(['client_seen_at' => now(), 'updated_at' => now()]);
            DB::table('visibility_run_claims')->where('tenant_id', $tenantId)
                ->where('user_id', $userId)->where('request_hash', $row->request_hash)
                ->where('request_key', $row->idempotency_key)->whereNull('released_at')
                ->update(['released_at' => now(), 'updated_at' => now()]);
        });

        return response()->json(['object' => 'visibility_run_seen', 'ok' => true]);
    }
}
