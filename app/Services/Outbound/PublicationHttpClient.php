<?php

namespace App\Services\Outbound;

use App\Services\AI\MagpieTargets;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Psr\Http\Message\RequestInterface;

/**
 * Applies Magpie reservations to publication and site-verification requests while
 * preserving their existing transport behavior on ports without a reservation.
 */
final class PublicationHttpClient
{
    public function __construct(
        private readonly MagpieTargets $targets,
        private readonly SafeOutboundHttpClient $outbound,
        private readonly FinalOutboundSecurityPolicy $policy,
    ) {}

    public function timeout(int $seconds): PendingRequest
    {
        return Http::timeout($seconds)->withMiddleware(function (callable $handler): callable {
            // Laravel appends this middleware inside Guzzle's redirect handler, so every
            // redirect is checked before sending. Callers supply no replacement handlers.
            return function (RequestInterface $request, array $options) use ($handler) {
                $uri = $request->getUri();
                $port = $uri->getPort() ?? ($uri->getScheme() === 'https' ? 443 : 80);
                if ($this->targets->reservedHosts($port) === []) {
                    return $handler($request, $options);
                }

                $guard = $this->policy->dynamicMiddleware($options, $this->outbound->withEnforcement()->resolveTarget(...));

                return $guard($handler)($request, $options);
            };
        });
    }
}
