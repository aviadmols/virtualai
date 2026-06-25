<?php

namespace App\Domain\Scan\Fetch;

/**
 * SystemHostResolver — the production DNS implementation of HostResolver.
 *
 * Resolves A (IPv4) and AAAA (IPv6) records for a hostname. An IP-literal host is
 * first NORMALISED (octal `0177.0.0.1`, hex `0x7f.0.0.1`, integer `2130706433` all
 * collapse to their canonical dotted-quad) and returned as-is — so an obfuscated
 * loopback literal is unmasked before UrlGuard checks it, never sent to DNS.
 */
final class SystemHostResolver implements HostResolver
{
    public function resolve(string $host): array
    {
        $normalisedLiteral = IpNormaliser::normalise($host);

        // The host is already an IP literal (possibly obfuscated): resolve to itself.
        if ($normalisedLiteral !== null) {
            return [$normalisedLiteral];
        }

        $ips = [];

        // IPv4 (A records).
        $a = gethostbynamel($host);
        if (is_array($a)) {
            $ips = array_merge($ips, $a);
        }

        // IPv6 (AAAA records) — best-effort; not every platform/host has them.
        $aaaa = @dns_get_record($host, DNS_AAAA);
        if (is_array($aaaa)) {
            foreach ($aaaa as $record) {
                if (isset($record['ipv6']) && is_string($record['ipv6'])) {
                    $ips[] = $record['ipv6'];
                }
            }
        }

        return array_values(array_unique($ips));
    }
}
