<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors for outbound request validation and transport.
 * Upstream: app/Services/Outbound/OutboundRequestFailedException.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\Outbound;

use RuntimeException;

final class OutboundRequestFailedException extends RuntimeException
{
    public readonly string $reasonCode;

    public function __construct(public readonly bool $beforeSend = false)
    {
        $this->reasonCode = 'outbound_request_failed';

        parent::__construct('Outbound request failed.');
    }

    public static function fromCause(mixed $cause): self
    {
        // Only DNS and connect refusal are proven unsent. Timeouts, resets and
        // all unclassified failures may have consumed tokens; never retry them.
        for ($depth = 0; $cause instanceof \Throwable && $depth < 8; $depth++, $cause = $cause->getPrevious()) {
            if ($cause instanceof self) {
                return $cause;
            }
            if ($cause instanceof \GuzzleHttp\Exception\ConnectException) {
                $errno = (int) ($cause->getHandlerContext()['errno'] ?? 0);
                if (in_array($errno, [6, 7], true)) {
                    return new self(beforeSend: true);
                }
            }
        }

        return new self;
    }
}
