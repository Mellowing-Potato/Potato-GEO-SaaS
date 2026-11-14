<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors for outbound request validation and transport.
 * Upstream: app/Services/Outbound/HardenedHandlerStack.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\Outbound;

use GuzzleHttp\HandlerStack;

final class HardenedHandlerStack
{
    public static function create(callable $trustedTerminalHandler): HandlerStack
    {
        $stack = HandlerStack::create($trustedTerminalHandler);
        $stack->remove('allow_redirects');
        $stack->remove('http_errors');

        return $stack;
    }
}
