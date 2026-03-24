<?php

declare(strict_types=1);

// Copyright 2024. All rights reserved.

/**
 * Reverse DNS helpers for building PTR record names and reverse zone names
 * from IPv4 and IPv6 addresses.
 */
class Modules_Powerdns_ReverseDns
{
    /**
     * Default IPv6 prefix length for reverse zone delegation (in bits).
     *
     * Common values: 48 (ISP delegation), 64 (subnet), 32 (large allocation).
     * Each nibble = 4 bits, so prefix 48 = 12 nibbles, 64 = 16 nibbles, etc.
     */
    private const DEFAULT_IPV6_PREFIX = 48;

    /**
     * Build the reverse DNS zone name from an IP address.
     *
     * IPv4: "192.168.1.100" -> "1.168.192.in-addr.arpa."  (always /24)
     * IPv6: depends on $ipv6Prefix (default /48):
     *   "2001:0db8:1234:..." -> "4.3.2.1.8.b.d.0.1.0.0.2.ip6.arpa."
     *
     * @param string $ip          IP address (v4 or v6)
     * @param int    $ipv6Prefix  IPv6 prefix length in bits (must be multiple of 4).
     *                            Common values: 32, 48 (default), 56, 64.
     */
    public static function buildReverseZone(string $ip, int $ipv6Prefix = self::DEFAULT_IPV6_PREFIX): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $octets = explode('.', $ip);
            $reversed = array_reverse(array_slice($octets, 0, 3));
            return implode('.', $reversed) . '.in-addr.arpa.';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // Prefix must be a positive multiple of 4 and at most 128 bits
            if ($ipv6Prefix <= 0 || $ipv6Prefix > 128 || $ipv6Prefix % 4 !== 0) {
                return null;
            }

            $expanded = self::expandIpv6($ip);
            if ($expanded === null) {
                return null;
            }

            // Convert prefix bits to nibble count (each nibble = 4 bits)
            $nibbleCount = intdiv($ipv6Prefix, 4);
            $nibbles = str_replace(':', '', $expanded);
            $zoneNibbles = array_reverse(str_split(substr($nibbles, 0, $nibbleCount)));
            return implode('.', $zoneNibbles) . '.ip6.arpa.';
        }

        return null;
    }

    /**
     * Build the full PTR record name from an IP address.
     *
     * IPv4: "192.168.1.100" -> "100.1.168.192.in-addr.arpa."
     * IPv6: "2001:db8::1"   -> "1.0.0.0...8.b.d.0.1.0.0.2.ip6.arpa."
     */
    public static function buildPtrName(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $octets = array_reverse(explode('.', $ip));
            return implode('.', $octets) . '.in-addr.arpa.';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $expanded = self::expandIpv6($ip);
            if ($expanded === null) {
                return null;
            }
            $nibbles = str_replace(':', '', $expanded);
            $reversed = array_reverse(str_split($nibbles));
            return implode('.', $reversed) . '.ip6.arpa.';
        }

        return null;
    }

    /**
     * Expand an IPv6 address to its full 32-nibble representation.
     * e.g., "2001:db8::1" -> "2001:0db8:0000:0000:0000:0000:0000:0001"
     */
    public static function expandIpv6(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }

        $hex = bin2hex($packed);
        // Insert colons every 4 characters
        return implode(':', str_split($hex, 4));
    }
}
