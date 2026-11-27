<?php

use App\Services\Api\ApiTokenIssuanceReceipt;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('api_idempotency_keys')
            ->select('id')->whereNotNull('response_body')->orderBy('id')
            ->chunkById(100, function ($rows): void {
                foreach ($rows as $candidate) {
                    DB::transaction(function () use ($candidate): void {
                        // Re-read under lock: a pending request may have completed
                        // since pagination. Never replace its response with a stale marker.
                        $row = DB::table('api_idempotency_keys')->where('id', $candidate->id)->lockForUpdate()->first();
                        if (! $row || $row->response_body === null) {
                            return;
                        }
                        $plain = $row->response_body;
                        $trimmed = ltrim($plain);
                        if (! str_starts_with($trimmed, '{') && ! str_starts_with($trimmed, '[')) {
                            // Unreadable ciphertext is not legacy JSON. Abort without
                            // overwriting it or swallowing a wrong-instance-key failure.
                            $plain = Crypt::decryptString($plain);
                        }
                        // Keep the reservation even if historical JSON is broken:
                        // deleting the row could turn a replay into another issuance.
                        if (ApiTokenIssuanceReceipt::matches($row->route_key)) {
                            $payload = json_decode($plain, true);
                            $plain = is_array($payload)
                                ? json_encode(ApiTokenIssuanceReceipt::withoutSecret($payload), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
                                : null;
                        }
                        DB::table('api_idempotency_keys')->where('id', $row->id)
                            ->update(['response_body' => $plain === null ? null : Crypt::encryptString($plain)]);
                    });
                }
            });
    }

    public function down(): void
    {
        // Removed credentials cannot and must not be reconstructed on rollback.
    }
};
