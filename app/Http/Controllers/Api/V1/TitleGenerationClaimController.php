<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\ApiIdempotencyKey;
use App\Models\Project;
use App\Models\TitleLibrary;
use App\Services\Api\IdempotencyService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** Recover one account's original paid title request before another model send. */
class TitleGenerationClaimController extends Controller
{
    private const ROUTE = 'api/v1/materials/titles/generate';

    public function claim(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'payload' => ['required', 'array'],
            'payload.project_id' => ['required', 'integer', 'min:1'],
            'payload.keywords' => ['required', 'array', 'min:1'],
            'payload.keywords.*' => ['required', 'string', 'max:120'],
            'payload.count' => ['nullable', 'integer', 'min:1', 'max:50'],
            'payload.style' => ['nullable', 'string', 'in:professional,attractive,seo,creative,question'],
            'payload.category' => ['nullable', 'string', 'max:120'],
            'payload.custom_prompt' => ['nullable', 'string', 'max:2000'],
            'request_key' => ['nullable', 'string', 'regex:/^[A-Za-z0-9_.:-]{1,160}$/'],
        ]);
        $tenantId = IdempotencyService::currentTenantId();
        $userId = (int) $request->user()->id;
        abort_unless(Project::query()->whereKey((int) $validated['payload']['project_id'])
            ->where('tenant_id', $tenantId)->exists(), 404);
        $hash = IdempotencyService::requestHash($validated['payload']);
        $candidate = $validated['request_key'] ?? 'material-titles-'.bin2hex(random_bytes(24));

        $claim = DB::transaction(function () use ($tenantId, $userId, $hash, $candidate): array {
            DB::table('title_generation_claims')->insertOrIgnore([
                'tenant_id' => $tenantId, 'user_id' => $userId,
                'request_hash' => $hash, 'request_key' => $candidate,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $row = DB::table('title_generation_claims')
                ->where('tenant_id', $tenantId)->where('user_id', $userId)
                ->where('request_hash', $hash)->lockForUpdate()->first();
            // A single old browser attempt can be recovered; two cannot be guessed apart.
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
                DB::table('title_generation_claims')->where('id', $row->id)
                    ->update(['request_key' => $key, 'released_at' => null, 'updated_at' => now()]);
            }
            return ['request_key' => $key, 'accepted' => $unseen->isNotEmpty()];
        });

        if (isset($claim['conflict'])) {
            return response()->json(['message' => '同内容的历史标题请求不止一笔，请先核对标题库，本次未发送。'], 409);
        }
        return response()->json(['object' => 'title_generation_claim', 'ok' => true] + $claim);
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
        $data = is_array($body) ? ($body['data'] ?? null) : null;
        $ids = is_array($data) ? array_map(static fn ($item) => is_array($item) ? ($item['id'] ?? null) : null, $data) : [];
        if (! $row || $row->response_status !== 201 || ($body['object'] ?? null) !== 'title_generation'
            || ! is_array($data) || ! array_is_list($data)
            || ! is_int($body['count'] ?? null) || $body['count'] !== count($data)
            || collect($data)->contains(static fn ($item) => ! is_array($item)
                || ! is_int($item['id'] ?? null) || $item['id'] < 1
                || ! is_string($item['title'] ?? null) || trim($item['title']) === '')
            || count(array_unique($ids)) !== count($ids)
            || TitleLibrary::query()->where('tenant_id', $tenantId)->whereIn('id', $ids)->count() !== count($ids)) {
            return response()->json(['message' => '原标题结果尚未核实，不能确认送达。'], 409);
        }
        DB::transaction(function () use ($tenantId, $userId, $row): void {
            ApiIdempotencyKey::query()->whereKey($row->id)->whereNull('client_seen_at')
                ->update(['client_seen_at' => now(), 'updated_at' => now()]);
            DB::table('title_generation_claims')->where('tenant_id', $tenantId)
                ->where('user_id', $userId)->where('request_hash', $row->request_hash)
                ->where('request_key', $row->idempotency_key)->whereNull('released_at')
                ->update(['released_at' => now(), 'updated_at' => now()]);
        });
        return response()->json(['object' => 'title_generation_seen', 'ok' => true]);
    }
}
