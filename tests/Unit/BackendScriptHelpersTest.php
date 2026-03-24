<?php
// Copyright 2024. All rights reserved.

declare(strict_types=1);

/**
 * Tests for the helper functions in powerdns.php backend script
 * (buildReverseZone, buildPtrName — IPv4 and IPv6).
 */

// Stub Plesk classes
if (!class_exists('pm_Exception')) {
    class pm_Exception extends \RuntimeException {}
}
if (!class_exists('pm_Settings')) {
    class pm_Settings {
        private static array $store = [];
        public static function get(string $key, $default = null) { return self::$store[$key] ?? $default; }
        public static function set(string $key, $value): void { self::$store[$key] = $value; }
    }
}

use PHPUnit\Framework\TestCase;

class BackendScriptHelpersTest extends TestCase
{
    // ── IPv4 ────────────────────────────────────────────

    public function testBuildReverseZoneIpv4(): void
    {
        $this->assertSame('1.168.192.in-addr.arpa.', $this->buildReverseZone('192.168.1.100'));
    }

    public function testBuildReverseZoneIpv4ClassA(): void
    {
        $this->assertSame('0.0.10.in-addr.arpa.', $this->buildReverseZone('10.0.0.1'));
    }

    public function testBuildReverseZoneInvalid(): void
    {
        $this->assertNull($this->buildReverseZone('not-an-ip'));
    }

    public function testBuildPtrNameIpv4(): void
    {
        $this->assertSame('100.1.168.192.in-addr.arpa.', $this->buildPtrName('192.168.1.100'));
    }

    public function testBuildPtrNameInvalid(): void
    {
        $this->assertNull($this->buildPtrName('invalid'));
    }

    // ── IPv6 ────────────────────────────────────────────

    public function testBuildReverseZoneIpv6Full(): void
    {
        // 2001:0db8:1234:5678:9abc:def0:1234:5678
        // /48 zone = first 12 nibbles: 2001:0db8:1234 → reversed nibbles
        $zone = $this->buildReverseZone('2001:0db8:1234:5678:9abc:def0:1234:5678');
        $this->assertSame('4.3.2.1.8.b.d.0.1.0.0.2.ip6.arpa.', $zone);
    }

    public function testBuildReverseZoneIpv6Compressed(): void
    {
        // 2001:db8::1 expands to 2001:0db8:0000:0000:0000:0000:0000:0001
        // /48 zone = first 12 nibbles: 200100db8000 → reversed
        $zone = $this->buildReverseZone('2001:db8::1');
        $this->assertSame('0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa.', $zone);
    }

    public function testBuildPtrNameIpv6Full(): void
    {
        $ptr = $this->buildPtrName('2001:0db8:1234:5678:9abc:def0:1234:5678');
        // All 32 nibbles reversed
        $this->assertSame(
            '8.7.6.5.4.3.2.1.0.f.e.d.c.b.a.9.8.7.6.5.4.3.2.1.8.b.d.0.1.0.0.2.ip6.arpa.',
            $ptr
        );
    }

    public function testBuildPtrNameIpv6Compressed(): void
    {
        $ptr = $this->buildPtrName('2001:db8::1');
        // 2001:0db8:0000:0000:0000:0000:0000:0001 → all 32 nibbles reversed
        $this->assertSame(
            '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa.',
            $ptr
        );
    }

    public function testBuildPtrNameIpv6Loopback(): void
    {
        $ptr = $this->buildPtrName('::1');
        $this->assertSame(
            '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.ip6.arpa.',
            $ptr
        );
    }

    public function testExpandIpv6(): void
    {
        $this->assertSame('2001:0db8:0000:0000:0000:0000:0000:0001', $this->expandIpv6('2001:db8::1'));
        $this->assertSame('0000:0000:0000:0000:0000:0000:0000:0001', $this->expandIpv6('::1'));
        $this->assertNull($this->expandIpv6('not-ipv6'));
    }

    // ── Copied helpers from powerdns.php for isolated testing ──

    private function buildReverseZone(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $octets = explode('.', $ip);
            $reversed = array_reverse(array_slice($octets, 0, 3));
            return implode('.', $reversed) . '.in-addr.arpa.';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $expanded = $this->expandIpv6($ip);
            if ($expanded === null) {
                return null;
            }
            $nibbles = str_replace(':', '', $expanded);
            $zoneNibbles = array_reverse(str_split(substr($nibbles, 0, 12)));
            return implode('.', $zoneNibbles) . '.ip6.arpa.';
        }

        return null;
    }

    private function buildPtrName(string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $octets = array_reverse(explode('.', $ip));
            return implode('.', $octets) . '.in-addr.arpa.';
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $expanded = $this->expandIpv6($ip);
            if ($expanded === null) {
                return null;
            }
            $nibbles = str_replace(':', '', $expanded);
            $reversed = array_reverse(str_split($nibbles));
            return implode('.', $reversed) . '.ip6.arpa.';
        }

        return null;
    }

    private function expandIpv6(string $ip): ?string
    {
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        $hex = bin2hex($packed);
        return implode(':', str_split($hex, 4));
    }
}
