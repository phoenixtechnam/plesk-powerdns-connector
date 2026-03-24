<?php

// Copyright 2024. All rights reserved.

declare(strict_types=1);

/**
 * Tests for the ReverseDns helper class (buildReverseZone, buildPtrName, expandIpv6).
 */

use PHPUnit\Framework\TestCase;

class ReverseDnsTest extends TestCase
{
    // ── IPv4 ────────────────────────────────────────────

    public function testBuildReverseZoneIpv4(): void
    {
        $this->assertSame('1.168.192.in-addr.arpa.', Modules_Powerdns_ReverseDns::buildReverseZone('192.168.1.100'));
    }

    public function testBuildReverseZoneIpv4ClassA(): void
    {
        $this->assertSame('0.0.10.in-addr.arpa.', Modules_Powerdns_ReverseDns::buildReverseZone('10.0.0.1'));
    }

    public function testBuildReverseZoneInvalid(): void
    {
        $this->assertNull(Modules_Powerdns_ReverseDns::buildReverseZone('not-an-ip'));
    }

    public function testBuildPtrNameIpv4(): void
    {
        $this->assertSame('100.1.168.192.in-addr.arpa.', Modules_Powerdns_ReverseDns::buildPtrName('192.168.1.100'));
    }

    public function testBuildPtrNameInvalid(): void
    {
        $this->assertNull(Modules_Powerdns_ReverseDns::buildPtrName('invalid'));
    }

    // ── IPv6 ────────────────────────────────────────────

    public function testBuildReverseZoneIpv6Full(): void
    {
        $zone = Modules_Powerdns_ReverseDns::buildReverseZone('2001:0db8:1234:5678:9abc:def0:1234:5678');
        $this->assertSame('4.3.2.1.8.b.d.0.1.0.0.2.ip6.arpa.', $zone);
    }

    public function testBuildReverseZoneIpv6Compressed(): void
    {
        $zone = Modules_Powerdns_ReverseDns::buildReverseZone('2001:db8::1');
        $this->assertSame('0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa.', $zone);
    }

    public function testBuildPtrNameIpv6Full(): void
    {
        $ptr = Modules_Powerdns_ReverseDns::buildPtrName('2001:0db8:1234:5678:9abc:def0:1234:5678');
        $this->assertSame(
            '8.7.6.5.4.3.2.1.0.f.e.d.c.b.a.9.8.7.6.5.4.3.2.1.8.b.d.0.1.0.0.2.ip6.arpa.',
            $ptr
        );
    }

    public function testBuildPtrNameIpv6Compressed(): void
    {
        $ptr = Modules_Powerdns_ReverseDns::buildPtrName('2001:db8::1');
        $this->assertSame(
            '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.8.b.d.0.1.0.0.2.ip6.arpa.',
            $ptr
        );
    }

    public function testBuildPtrNameIpv6Loopback(): void
    {
        $ptr = Modules_Powerdns_ReverseDns::buildPtrName('::1');
        $this->assertSame(
            '1.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.0.ip6.arpa.',
            $ptr
        );
    }

    public function testExpandIpv6(): void
    {
        $this->assertSame('2001:0db8:0000:0000:0000:0000:0000:0001', Modules_Powerdns_ReverseDns::expandIpv6('2001:db8::1'));
        $this->assertSame('0000:0000:0000:0000:0000:0000:0000:0001', Modules_Powerdns_ReverseDns::expandIpv6('::1'));
        $this->assertNull(Modules_Powerdns_ReverseDns::expandIpv6('not-ipv6'));
    }

    // ── Configurable IPv6 prefix ────────────────────

    public function testBuildReverseZoneIpv6Prefix64(): void
    {
        // /64 = 16 nibbles: 2001:0db8:1234:5678 reversed
        $zone = Modules_Powerdns_ReverseDns::buildReverseZone('2001:0db8:1234:5678:9abc:def0:1234:5678', 64);
        $this->assertSame('8.7.6.5.4.3.2.1.8.b.d.0.1.0.0.2.ip6.arpa.', $zone);
    }

    public function testBuildReverseZoneIpv6Prefix32(): void
    {
        // /32 = 8 nibbles: 2001:0db8 reversed
        $zone = Modules_Powerdns_ReverseDns::buildReverseZone('2001:0db8:1234:5678:9abc:def0:1234:5678', 32);
        $this->assertSame('8.b.d.0.1.0.0.2.ip6.arpa.', $zone);
    }

    public function testBuildReverseZoneIpv6InvalidPrefixReturnsNull(): void
    {
        // Prefix 0 — invalid
        $this->assertNull(Modules_Powerdns_ReverseDns::buildReverseZone('2001:db8::1', 0));
        // Prefix 5 — not a multiple of 4
        $this->assertNull(Modules_Powerdns_ReverseDns::buildReverseZone('2001:db8::1', 5));
        // Prefix 200 — exceeds 128
        $this->assertNull(Modules_Powerdns_ReverseDns::buildReverseZone('2001:db8::1', 200));
        // Negative prefix
        $this->assertNull(Modules_Powerdns_ReverseDns::buildReverseZone('2001:db8::1', -4));
    }

    public function testBuildReverseZoneIpv6DefaultIs48(): void
    {
        // Default (no prefix arg) should be /48 = 12 nibbles
        $withDefault = Modules_Powerdns_ReverseDns::buildReverseZone('2001:0db8:1234:5678:9abc:def0:1234:5678');
        $withExplicit = Modules_Powerdns_ReverseDns::buildReverseZone('2001:0db8:1234:5678:9abc:def0:1234:5678', 48);
        $this->assertSame($withExplicit, $withDefault);
    }
}
