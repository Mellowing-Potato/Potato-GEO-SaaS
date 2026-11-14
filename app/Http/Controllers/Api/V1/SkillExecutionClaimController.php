<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiIdempotencyKey;
use App\Models\Project;
use App\Models\SkillExecution;
use App\Services\Api\IdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Restore the original async skill request before another paid task is queued. */
class SkillExecutionClaimController extends Controller
{
    private const ROUTE = 'api/v1/skills/execute-async';

    public function claim(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payload' => ['required', 'array'],
            'payload.skill_id' => ['required', 'string'],
            'payload.project_id' => ['required', 'integer', 'min:1'],
            'payload.fields' => ['nullable', 'array'],
            'payload.query' => ['nullable', 'string', 'max:2000'],
            'payload.material_refs' => ['nullable', 'array'],
            'payload.material_refs.*.type' => ['required_with:payload.material_refs', 'string', 'in:title,keyword,author'],
            'payload.material_refs.*.id' => ['required_with:payload.material_refs', 'integer', 'min:1'],
            'request_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_.:-]{1,160}$/'],
        ]);
        $tenantId = IdempotencyService::currentTenantId();
        $userId = (int) $request->user()->id;
        abort_unless(Project::query()->whereKey((int) $validated['payload']['project_id'])
            ->where('tenant_id', $tenantId)->exists(), 404);
        $hash = IdempotencyService::requestHash($validated['payload']);
        $candidate = $validated['request_key'] ?? 'skill-execution-'.bin2hex(random_bytes(24));

        $claim = DB::transaction(function () use ($tenantId, $userId, $hash, $candidate, $validated): array {
            DB::table('skill_execution_claims')->insertOrIgnore([
                'tenant_id' => $tenantId, 'user_id' => $userId,
                'request_hash' => $hash, 'request_key' => $candidate,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('skill_execution_claims')
                ->where('tenant_id', $tenantId)->where('user_id', $userId)
                ->where('request_hash', $hash)->lockForUpdate()->first();
            $unseen = ApiIdempotencyKey::query()
                ->where('tenant_id', $tenantId)->where('user_id', $userId)
                ->where('route_key', self::ROUTE)->where('request_hash', $hash)
                ->whereNull('client_seen_at')
                ->where(function ($query): void {
                    $query->whereNull('response_status')->orWhereNotIn('response_status', [402, 422]);
                })->orderBy('id')->limit(2)->get();
            if ($unseen->count() > 1) {
                return ['conflict' => true];
            }
            $old = ApiIdempotencyKey::query()->where('tenant_id', $tenantId)
                ->where('user_id', $userId)->where('route_key', self::ROUTE)
                ->where('idempotency_key', $row->request_key)->first();
            $safeUnsent = in_array($old?->response_status, [402, 422], true);
            $key = $unseen->first()?->idempotency_key
                ?? ($row->released_at === null && ! $safeUnsent ? $row->request_key : $candidate);
            if ($row->request_key !== $key || $row->released_at !== null) {
                DB::table('skill_execution_claims')->where('id', $row->id)
                    ->update(['request_key' => $key, 'released_at' => null, 'updated_at' => now()]);
            }
            $original = ApiIdempotencyKey::query()->where('tenant_id', $tenantId)
                ->where('user_id', $userId)->where('route_key', self::ROUTE)
                ->where('idempotency_key', $key)->where('request_hash', $hash)->first();
            $recovered = false;
            if ($original && $original->response_status === null) {
                // The execution insert and its request key are one durable row.
                // A merely pending row does not prove queue dispatch happened.
                $execution = SkillExecution::query()->where('tenant_id', $tenantId)
                    ->where('user_id', $userId)->where('client_request_key', $key)
                    ->where('project_id', (int) $validated['payload']['project_id'])
                    ->where('skill_id', $validated['payload']['skill_id'])
                    ->whereIn('status', ['running', 'billing_pending', 'completed', 'failed', 'cancelled'])
                    ->first();
                if ($execution) {
                    IdempotencyService::store($key, self::ROUTE, $hash, [
                        'execution' => [
                            'id' => $execution->id,
                            'project_id' => $execution->project_id,
                            'user_id' => $execution->user_id,
                            'skill_id' => $execution->skill_id,
                            'status' => $execution->status,
                        ],
                        'message' => 'Original skill execution recovered from the durable task record.',
                    ], 202, $tenantId, $userId);
                    $recovered = true;
                }
            }
            return ['request_key' => $key, 'accepted' => $unseen->isNotEmpty(),
                'recovered_execution' => $recovered];
        });
        if (isset($claim['conflict'])) {
            return response()->json(['message' => '同内容的历史技能任务不止一笔，请先核对任务列表，本次未发送。'], 409);
        }
        return response()->json(['object' => 'skill_execution_claim', 'ok' => true] + $claim);
    }

    public function seen(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'request_key' => ['required', 'string', 'regex:/^[A-Za-z0-9_.:-]{1,160}$/'],
        ]);
        $tenantId = IdempotencyService::currentTenantId();
        $userId = (int) $request->user()->id;
        $row = ApiIdempotencyKey::query()->where('tenant_id', $tenantId)
            ->where('user_id', $userId)->where('route_key', self::ROUTE)
            ->where('idempotency_key', $validated['request_key'])->first();
        $body = json_decode((string) $row?->response_body, true);
        $execution = is_array($body) ? ($body['execution'] ?? null) : null;
        $executionId = is_array($execution) ? ($execution['id'] ?? null) : null;
        if (! $row || $row->response_status !== 202 || ! is_int($executionId) || $executionId < 1
            || ! is_array($execution) || ! is_int($execution['project_id'] ?? null)
            || ! is_int($execution['user_id'] ?? null) || ! is_string($execution['skill_id'] ?? null)
            || $execution['user_id'] !== $userId
            || ! SkillExecution::query()->whereKey($executionId)->where('tenant_id', $tenantId)
                ->where('user_id', $userId)->where('project_id', $execution['project_id'])
                ->where('skill_id', $execution['skill_id'])->exists()) {
            return response()->json(['message' => '原技能任务尚未核实，不能确认送达。'], 409);
        }
        DB::transaction(function () use ($tenantId, $userId, $row): void {
            ApiIdempotencyKey::query()->whereKey($row->id)->whereNull('client_seen_at')
                ->update(['client_seen_at' => now(), 'updated_at' => now()]);
            DB::table('skill_execution_claims')->where('tenant_id', $tenantId)
                ->where('user_id', $userId)->where('request_hash', $row->request_hash)
                ->where('request_key', $row->idempotency_key)->whereNull('released_at')
                ->update(['released_at' => now(), 'updated_at' => now()]);
        });
        return response()->json(['object' => 'skill_execution_seen', 'ok' => true]);
    }
}
