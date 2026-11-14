<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors for outbound request validation and transport.
 * Upstream: app/Services/Outbound/OutboundRequestBlockedException.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\Outbound;

use RuntimeException;

final class OutboundRequestBlockedException extends RuntimeException
{
    public function __construct(public readonly string $reasonCode)
    {
        parent::__construct('Outbound request blocked by security policy.');
    }
}
