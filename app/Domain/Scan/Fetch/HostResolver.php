<?php

namespace App\Domain\Scan\Fetch;

/**
 * HostResolver — the injectable DNS seam.
 *
 * SSRF defence cannot stop at the host STRING: a hostname (or an octal/hex/integer
 * IPv4 literal) can RESOLVE to a private/metadata address. UrlGuard resolves the
 * host through this seam and rejects when ANY resolved address is private/reserved.
 *
 * Behind an interface so tests can return a private IP for a fake host with NO real
 * DNS lookup — the egress guards are then provable network-free. Production binds
 * SystemHostResolver (real A/AAAA lookups).
 */
interface HostResolver
{
    /**
     * Resolve a host to its IP literals (IPv4 A + IPv6 AAAA). An IP-literal host
     * resolves to itself (after normalising octal/hex/integer IPv4 forms).
     *
     * @return array<int,string> the resolved IP literals; empty when unresolvable.
     */
    public function resolve(string $host): array;
}
