<?php

namespace App\Services\AI;

final readonly class MagpieTarget
{
    public string $baseUrl;

    /** @param list<string> $addresses */
    public function __construct(
        public string $alias,
        public string $origin,
        public string $host,
        public int $port,
        public string $authMode,
        public string $revision,
        public array $addresses,
    ) {
        $this->baseUrl = $origin.'/v1';
    }
}
