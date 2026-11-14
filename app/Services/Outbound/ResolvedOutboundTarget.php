<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors for outbound request validation and transport.
 * Upstream: app/Services/Outbound/ResolvedOutboundTarget.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\Outbound;

final readonly class ResolvedOutboundTarget
{
    /**
     * @param  list<string>  $addresses
     */
    public function __construct(
        public string $url,
        public string $scheme,
        public string $host,
        public int $port,
        public array $addresses,
        public string $selectedIp,
    ) {}
}
