<?php

namespace App\Services\AI;

use App\Support\TenantContext;

final class MagpieTargets
{
    /** @return list<array{alias: string, base_url: string, auth_mode: string, revision: string}> */
    public function available(): array
    {
        $result = [];
        foreach (array_keys($this->configured()) as $alias) {
            if (! is_string($alias)) {
                continue;
            }
            try {
                $target = $this->forTenant($alias);
                $result[] = ['alias' => $alias, 'base_url' => $target->baseUrl, 'auth_mode' => $target->authMode, 'revision' => $target->revision];
            } catch (MagpieException) {
                continue;
            }
        }

        return $result;
    }

    public function forTenant(string $alias): MagpieTarget
    {
        $tenantId = TenantContext::id();
        $definition = $this->configured()[$alias] ?? [];
        $origin = $this->origin($definition['origin'] ?? null);
        $authMode = $definition['auth_mode'] ?? null;
        $tenantIds = $definition['tenant_ids'] ?? null;
        $revision = $definition['revision'] ?? null;
        $pins = $definition['addresses'] ?? [];

        if ($tenantId === null || $tenantId <= 0
            || preg_match('/\A[A-Za-z0-9][A-Za-z0-9_.-]{0,63}\z/', $alias) !== 1
            || $origin === null || ($definition['enabled'] ?? false) !== true
            || ! is_array($tenantIds) || ! in_array($tenantId, $tenantIds, true)
            || (! is_int($revision) && (! is_string($revision) || $revision === ''))
            || ! in_array($authMode, ['gateway_key', 'loopback'], true)
            || ! is_array($pins) || ! array_is_list($pins)) {
            throw new MagpieException('target_unauthorized');
        }
        [$url, $host, $port] = $origin;
        foreach ($pins as $ip) {
            if (! is_string($ip) || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                throw new MagpieException('target_unauthorized');
            }
        }

        $literal = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $loopback = $host === 'localhost' || ($literal && self::isLoopback($host));
        if (($authMode === 'loopback' && ! $loopback)
            || (! $literal && ! $loopback && $host !== 'host.docker.internal' && $pins === [])) {
            throw new MagpieException('target_unauthorized');
        }

        $revision = hash('sha256', serialize([$revision, $url, $authMode, $pins]));

        return new MagpieTarget($alias, $url, $host, $port, $authMode, $revision, $pins);
    }

    /**
     * A disabled target remains reserved. Remove its registration only after it
     * no longer serves Magpie or is unreachable from GEO's general outbound paths.
     *
     * @return array<string, list<string>>
     */
    public function reservedHosts(int $port): array
    {
        $hosts = [];
        foreach ($this->configured() as $definition) {
            $origin = $this->origin($definition['origin'] ?? null);
            if ($origin !== null && $origin[2] === $port) {
                $pins = $definition['addresses'] ?? [];
                $hosts[$origin[1]] = array_merge($hosts[$origin[1]] ?? [], is_array($pins) ? $pins : []);
            }
        }

        return $hosts;
    }

    public static function isLoopback(string $ip): bool
    {
        return (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false && str_starts_with($ip, '127.'))
            || @inet_pton($ip) === inet_pton('::1');
    }

    private function configured(): array
    {
        $targets = config('geo.magpie_targets', []);

        return is_array($targets) ? array_filter($targets, 'is_array') : [];
    }

    /** @return array{string,string,int}|null */
    private function origin(mixed $origin): ?array
    {
        if (! is_string($origin)
            || preg_match('~\A(https?)://(\[[a-f0-9:]+\]|[a-z0-9.-]+)(?::([0-9]{1,5}))?/?\z~i', $origin, $matches) !== 1) {
            return null;
        }
        $scheme = strtolower($matches[1]);
        $host = rtrim(strtolower(trim($matches[2], '[]')), '.');
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $port = (int) (($matches[3] ?? '') === '' ? $defaultPort : $matches[3]);
        if ($host === '' || $port < 1 || $port > 65535) {
            return null;
        }
        $hostForUrl = str_contains($host, ':') ? '['.$host.']' : $host;

        return [$scheme.'://'.$hostForUrl.($port === $defaultPort ? '' : ':'.$port), $host, $port];
    }
}
