<?php

namespace App\Services\Distribution;

class DistributionRetryPolicy
{
    /**
     * Maximum number of retry attempts.
     */
    public const MAX_ATTEMPTS = 3;

    /**
     * Determine if the failure is retryable.
     */
    public function isRetryable(int $attemptCount, ?int $httpStatus, ?string $errorType = null): bool
    {
        if ($attemptCount >= self::MAX_ATTEMPTS) {
            return false;
        }

        // Never retry auth/permission/validation errors
        if (in_array($httpStatus, [401, 403, 422], true)) {
            return false;
        }

        // Never retry signature errors
        if ($errorType === 'signature_error') {
            return false;
        }

        // Retry on: timeout, connection errors, 429 (rate limit), 5xx server errors
        if ($httpStatus === null || $httpStatus === 429 || $httpStatus >= 500) {
            return true;
        }

        return false;
    }

    /**
     * Calculate backoff delay in seconds.
     * Formula: min(3600, 60 * 2^(attempt-1))
     */
    public function backoffSeconds(int $attemptCount): int
    {
        return (int) min(3600, 60 * pow(2, max(0, $attemptCount - 1)));
    }

    /**
     * Get the next retry timestamp.
     */
    public function nextRetryAt(int $attemptCount): \DateTimeImmutable
    {
        return new \DateTimeImmutable('+' . $this->backoffSeconds($attemptCount) . ' seconds');
    }
}
