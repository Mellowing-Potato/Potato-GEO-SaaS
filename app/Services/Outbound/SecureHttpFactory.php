<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted outbound validation and transport for Yanji model and workspace requests.
 * Upstream: app/Services/Outbound/SecureHttpFactory.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\Outbound;

use Closure;
use GuzzleHttp\Middleware;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\Client\Factory;

final class SecureHttpFactory extends Factory
{
    /** @var list<callable> */
    private array $secureGlobalRequestMiddleware = [];

    /** @var list<callable> */
    private array $secureGlobalResponseMiddleware = [];

    /**
     * @param  Closure(string): ResolvedOutboundTarget  $resolveTarget
     */
    public function __construct(
        ?Dispatcher $dispatcher,
        private readonly FinalOutboundSecurityPolicy $securityPolicy,
        private readonly Closure $resolveTarget,
        private readonly Closure $trustedTerminalHandler,
        private readonly object $fixedContextCapability,
    ) {
        parent::__construct($dispatcher);
    }

    public function globalMiddleware($middleware)
    {
        throw new OutboundRequestBlockedException('generic_http_middleware_forbidden');
    }

    public function globalRequestMiddleware($middleware)
    {
        $this->secureGlobalRequestMiddleware[] = Middleware::mapRequest($middleware);

        return $this;
    }

    public function globalResponseMiddleware($middleware)
    {
        $this->secureGlobalResponseMiddleware[] = Middleware::mapResponse($middleware);

        return $this;
    }

    public function getGlobalMiddleware()
    {
        return [
            ...$this->secureGlobalRequestMiddleware,
            ...$this->secureGlobalResponseMiddleware,
        ];
    }

    protected function newPendingRequest()
    {
        return (new SecurePendingRequest(
            $this,
            $this->secureGlobalRequestMiddleware,
            $this->secureGlobalResponseMiddleware,
            $this->securityPolicy,
            $this->resolveTarget,
            $this->trustedTerminalHandler,
            $this->fixedContextCapability,
        ))->withOptions(value($this->globalOptions));
    }
}
