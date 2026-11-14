<?php

namespace App\Services\Content;

use App\Models\ApiIdempotencyKey;
use App\Services\Api\IdempotencyService;
use App\Services\DTOs\AiProviderCallResult;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/** A durable intent and encrypted result; reentry never repeats a supplier call. */
final class TitleGenerationCheckpoint
{
    public function run(Request $request, callable $generate): array
    {
        [$row, $send] = DB::transaction(function () use ($request): array {
            $row = ApiIdempotencyKey::query()
                ->where('tenant_id', IdempotencyService::currentTenantId())
                ->where('user_id', IdempotencyService::currentUserId())
                ->where('route_key', $request->path())
                ->where('idempotency_key', IdempotencyService::requestKey($request))
                ->where('request_hash', IdempotencyService::requestHash($request->all()))
                ->lockForUpdate()->firstOrFail();
            if ($row->model_checkpoint !== null || ! $request->attributes->get('title_first_attempt', false)) {
                return [$row, false];
            }
            $row->forceFill(['model_checkpoint' => ['state' => 'prepared']])->save();
            return [$row, true];
        });
        if (! $send) {
            $saved = $row->model_checkpoint;
            if (($saved['state'] ?? null) === 'result') {
                $result = $saved['result'];
                $result['call_result'] = is_array($result['call_result'] ?? null)
                    ? new AiProviderCallResult(...$result['call_result']) : null;
                return $result;
            }
            return ['titles' => [], 'fallback_used' => false, 'fallback_reason' => null,
                'call_result' => null, 'pending' => true];
        }
        $result = $generate();
        $saved = $result;
        $saved['call_result'] = $result['call_result'] === null ? null : get_object_vars($result['call_result']);
        $row->forceFill(['model_checkpoint' => ['state' => 'result', 'result' => $saved]])->save();
        return $result;
    }
}
