<?php

namespace App\Services\Outbound;

use App\Services\AI\MagpieException;

/** Operator-consented, credential-free discovery of one exact local endpoint. */
final class LocalMagpieDiscovery
{
    public function __construct(private readonly SafeOutboundHttpClient $outbound, private readonly SecureHttpFactory $http) {}

    public function probe(bool $authorized = false): array
    {
        $scope = MagpieRequestScope::forLocalDiscovery($authorized);
        try {
            $response = $this->outbound->withEnforcement()->send(
                $this->http->acceptJson()->connectTimeout(1)->timeout(2),
                'GET', $scope->url(), [], 16384, magpie: $scope,
            );
        } catch (OutboundRequestBlockedException|OutboundRequestFailedException) {
            throw new MagpieException('unreachable');
        }
        $version = $response->json('version');
        if (! $response->ok() || $response->json('name') !== 'magpie' || ! is_string($version)
            || preg_match('/\A[A-Za-z0-9._+\-]{1,80}\z/', $version) !== 1) {
            throw new MagpieException('invalid_identity');
        }
        return ['name' => 'magpie', 'version' => $version];
    }
}
