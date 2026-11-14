<?php

/**
 * Derived from GEOFlow (Apache-2.0), Copyright 2026 Yao Jingang.
 * Modified by Yanji contributors. Adapted outbound validation and transport for Yanji model and workspace requests.
 * Upstream: app/Services/Outbound/ResponseSizeLimitedStream.php (v2.3.0).
 * See NOTICE and licenses/ported-files.json for provenance.
 */

namespace App\Services\Outbound;

use GuzzleHttp\Psr7\StreamDecoratorTrait;
use Psr\Http\Message\StreamInterface;

final class ResponseSizeLimitedStream implements StreamInterface
{
    use StreamDecoratorTrait;

    private int $position;

    public function __construct(
        private StreamInterface $stream,
        private readonly int $maxBytes,
    ) {
        $this->position = $stream->tell();
        $this->assertWithinLimit($this->position);
    }

    public function read($length): string
    {
        if ($length === 0) {
            return '';
        }

        $remaining = $this->maxBytes - $this->position;
        $probeLength = min((int) $length, max(1, $remaining + 1));
        $chunk = $this->stream->read($probeLength);
        $this->position += strlen($chunk);
        $this->assertWithinLimit($this->position);

        return $chunk;
    }

    public function seek($offset, $whence = SEEK_SET): void
    {
        $this->stream->seek($offset, $whence);
        $this->position = $this->stream->tell();
        $this->assertWithinLimit($this->position);
    }

    private function assertWithinLimit(int $bytes): void
    {
        if ($bytes > $this->maxBytes) {
            throw new OutboundRequestBlockedException('response_too_large');
        }
    }
}
