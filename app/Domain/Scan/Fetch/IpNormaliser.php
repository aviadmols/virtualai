<?php

namespace App\Domain\Scan\Fetch;

/**
 * IpNormaliser — unmasks obfuscated IPv4 literals and decides if an IP is public.
 *
 * SSRF bypasses hide a private address behind a non-dotted-quad form the OS still
 * accepts: octal `0177.0.0.1`, hex `0x7f.0.0.1`, dword integer `2130706433`,
 * short forms `127.1`. We normalise ALL of those to canonical dotted-quad BEFORE
 * the range check, so `0177.0.0.1` is judged as `127.0.0.1` (loopback) and refused.
 *
 * isPublic() rejects every private / loopback / link-local / reserved range
 * (incl. the cloud metadata 169.254.169.254), and unwraps IPv4-mapped IPv6
 * (::ffff:127.0.0.1) so a mapped private v4 cannot sneak through the v6 path.
 */
final class IpNormaliser
{
    // === BLOCKED IPv4 RANGES (CIDR) ===
    // Every non-globally-routable v4 block. A resolved address in ANY of these is
    // refused — covers RFC1918 private, loopback, link-local incl. 169.254.169.254,
    // "this host", CGNAT, benchmarking, documentation, multicast and reserved.
    private const BLOCKED_V4_CIDRS = [
        '0.0.0.0/8',        // "this host" / 0/8
        '10.0.0.0/8',       // RFC1918 private
        '100.64.0.0/10',    // CGNAT (RFC6598)
        '127.0.0.0/8',      // loopback
        '169.254.0.0/16',   // link-local incl. 169.254.169.254 (cloud metadata)
        '172.16.0.0/12',    // RFC1918 private
        '192.0.0.0/24',     // IETF protocol assignments
        '192.0.2.0/24',     // TEST-NET-1 (documentation)
        '192.168.0.0/16',   // RFC1918 private
        '198.18.0.0/15',    // benchmarking
        '198.51.100.0/24',  // TEST-NET-2
        '203.0.113.0/24',   // TEST-NET-3
        '224.0.0.0/4',      // multicast
        '240.0.0.0/4',      // reserved / future use (incl. 255.255.255.255)
    ];

    // === BLOCKED IPv6 RANGES (CIDR) ===
    private const BLOCKED_V6_CIDRS = [
        '::1/128',          // loopback
        '::/128',           // unspecified
        '::ffff:0:0/96',    // IPv4-mapped (unwrapped separately, blocked here too)
        '64:ff9b::/96',     // NAT64
        '100::/64',         // discard-only
        'fc00::/7',         // unique local (private)
        'fe80::/10',        // link-local
        'ff00::/8',         // multicast
        '2001:db8::/32',    // documentation
    ];

    /**
     * Normalise any IPv4 literal form (octal/hex/dword/short) to canonical
     * dotted-quad, or pass a valid IPv6 literal through. Returns null when $host is
     * not an IP literal at all (a real hostname → caller resolves via DNS).
     */
    public static function normalise(string $host): ?string
    {
        $host = self::stripV6Brackets($host);

        // A canonical IPv6 literal: validate + return its compressed form.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return inet_ntop(inet_pton($host)) ?: $host;
        }

        // An already-canonical dotted-quad IPv4.
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $host;
        }

        // An obfuscated IPv4 form (octal/hex/dword/short). ip2long understands
        // these via inet_aton-style parsing of the recombined long; we decode the
        // parts ourselves so octal/hex are honoured, then long2ip back.
        $long = self::parseObfuscatedV4($host);
        if ($long !== null) {
            return long2ip($long);
        }

        return null; // not an IP literal — it is a hostname.
    }

    /** True only for a globally-routable address (not private/loopback/reserved). */
    public static function isPublic(string $ip): bool
    {
        $ip = self::stripV6Brackets($ip);

        // Unwrap an IPv4-mapped IPv6 (::ffff:127.0.0.1) to judge the v4 inside it.
        $mappedV4 = self::mappedV4($ip);
        if ($mappedV4 !== null) {
            $ip = $mappedV4;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            foreach (self::BLOCKED_V4_CIDRS as $cidr) {
                if (self::v4InCidr($ip, $cidr)) {
                    return false;
                }
            }

            // Defence in depth: PHP's own private/reserved filter on top of the list.
            return filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            foreach (self::BLOCKED_V6_CIDRS as $cidr) {
                if (self::v6InCidr($ip, $cidr)) {
                    return false;
                }
            }

            return filter_var(
                $ip,
                FILTER_VALIDATE_IP,
                FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
            ) !== false;
        }

        // Not a parseable IP → never treat as public.
        return false;
    }

    private static function stripV6Brackets(string $host): string
    {
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            return substr($host, 1, -1);
        }

        return $host;
    }

    /** The dotted-quad inside an IPv4-mapped IPv6, or null. */
    private static function mappedV4(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if ($packed === false || strlen($packed) !== 16) {
            return null;
        }

        // ::ffff:a.b.c.d → first 10 bytes zero, bytes 11-12 = 0xffff.
        $prefix = substr($packed, 0, 12);
        if ($prefix === "\0\0\0\0\0\0\0\0\0\0\xff\xff") {
            return inet_ntop(substr($packed, 12)) ?: null;
        }

        return null;
    }

    /** Parse octal/hex/dword/short IPv4 forms to an unsigned long, or null. */
    private static function parseObfuscatedV4(string $host): ?int
    {
        if ($host === '' || ! preg_match('/^[0-9a-fx.]+$/i', $host)) {
            return null;
        }

        $parts = explode('.', $host);
        if (count($parts) === 0 || count($parts) > 4) {
            return null;
        }

        $numbers = [];
        foreach ($parts as $part) {
            $n = self::parseIntPart($part);
            if ($n === null) {
                return null;
            }
            $numbers[] = $n;
        }

        // inet_aton-style packing: the LEADING parts are one byte each, MOST
        // significant first; the LAST part fills the remaining low bytes
        // (127.1 → 127.0.0.1; 2130706433 → 127.0.0.1; 0177.0.0.1 → 127.0.0.1).
        $count = count($numbers);
        $last = array_pop($numbers);
        $lastBytes = 4 - ($count - 1);            // low bytes the last part fills
        $maxLast = (2 ** ($lastBytes * 8)) - 1;
        if ($last < 0 || $last > $maxLast) {
            return null;
        }

        $long = $last;
        // Leading byte i (0-indexed) sits at the high end, descending.
        foreach ($numbers as $i => $byte) {
            if ($byte < 0 || $byte > 255) {
                return null;
            }
            $shift = (3 - $i) * 8;
            $long += $byte << $shift;
        }

        if ($long < 0 || $long > 0xFFFFFFFF) {
            return null;
        }

        return $long;
    }

    /** Parse a single IPv4 part honouring hex (0x), octal (0) and decimal. */
    private static function parseIntPart(string $part): ?int
    {
        if ($part === '') {
            return null;
        }

        if (preg_match('/^0x[0-9a-f]+$/i', $part)) {
            return (int) hexdec($part);
        }

        if (preg_match('/^0[0-7]+$/', $part)) {
            return (int) octdec($part);
        }

        if (preg_match('/^[0-9]+$/', $part)) {
            return (int) $part;
        }

        return null;
    }

    private static function v4InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipLong = ip2long($ip);
        $subnetLong = ip2long($subnet);
        if ($ipLong === false || $subnetLong === false) {
            return false;
        }

        $mask = $bits === '0' ? 0 : (-1 << (32 - (int) $bits)) & 0xFFFFFFFF;

        return ($ipLong & $mask) === ($subnetLong & $mask);
    }

    private static function v6InCidr(string $ip, string $cidr): bool
    {
        [$subnet, $bits] = explode('/', $cidr);
        $ipPacked = @inet_pton($ip);
        $subnetPacked = @inet_pton($subnet);
        if ($ipPacked === false || $subnetPacked === false) {
            return false;
        }

        $bits = (int) $bits;
        $bytes = intdiv($bits, 8);
        $remainder = $bits % 8;

        if ($bytes > 0 && strncmp($ipPacked, $subnetPacked, $bytes) !== 0) {
            return false;
        }

        if ($remainder === 0) {
            return true;
        }

        $mask = 0xFF << (8 - $remainder) & 0xFF;

        return (ord($ipPacked[$bytes]) & $mask) === (ord($subnetPacked[$bytes]) & $mask);
    }
}
