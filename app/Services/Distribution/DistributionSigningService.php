<?php

namespace App\Services\Distribution;

class DistributionSigningService
{
    /**
     * Sign a request following GEOFlow Agent signing protocol.
     * Canonical string: METHOD\nPATH\nTIMESTAMP\nNONCE\nBODY_HASH
     *
     * @return array<string, string> Headers to add to the request
     */
    public function signRequest(
        string $method,
        string $url,
        string $body,
        string $keyId,
        string $secret,
        ?string $idempotencyKey = null,
        ?string $event = null,
    ): array {
        $timestamp = (string) time();
        $nonce = bin2hex(random_bytes(16));
        $path = parse_url($url, PHP_URL_PATH) ?: '/';
        $bodyHash = hash('sha256', $body);

        $canonicalString = implode("\n", [
            strtoupper($method),
            $path,
            $timestamp,
            $nonce,
            $bodyHash,
        ]);

        $signature = hash_hmac('sha256', $canonicalString, $secret);

        $headers = [
            'X-GEOFlow-Key-Id' => $keyId,
            'X-GEOFlow-Timestamp' => $timestamp,
            'X-GEOFlow-Nonce' => $nonce,
            'X-GEOFlow-Body-SHA256' => $bodyHash,
            'X-GEOFlow-Signature' => $signature,
        ];

        if ($idempotencyKey !== null) {
            $headers['X-GEOFlow-Idempotency-Key'] = $idempotencyKey;
        }

        if ($event !== null) {
            $headers['X-GEOFlow-Event'] = $event;
        }

        return $headers;
    }

    /**
     * Verify an incoming signature (for target site callbacks).
     */
    public function verifySignature(
        string $method,
        string $path,
        string $body,
        string $timestamp,
        string $nonce,
        string $bodyHash,
        string $signature,
        string $secret,
        int $maxClockSkew = 300,
    ): bool {
        // Check timestamp freshness
        if (abs(time() - (int) $timestamp) > $maxClockSkew) {
            return false;
        }

        // Verify body hash
        if (!hash_equals(hash('sha256', $body), $bodyHash)) {
            return false;
        }

        // Reconstruct and verify signature
        $canonicalString = implode("\n", [
            strtoupper($method),
            $path,
            $timestamp,
            $nonce,
            $bodyHash,
        ]);

        $expectedSignature = hash_hmac('sha256', $canonicalString, $secret);

        return hash_equals($expectedSignature, $signature);
    }
}
