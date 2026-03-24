<?php

// Copyright 2024. All rights reserved.

declare(strict_types=1);

/**
 * Unit tests for the ZoneFormatter.
 *
 * These tests verify the translation from Plesk's DNS zone JSON
 * format to PowerDNS rrset format.  They do NOT require a running
 * PowerDNS instance or a Plesk environment.
 */

use PHPUnit\Framework\TestCase;

class ZoneFormatterTest extends TestCase
{
    private Modules_Powerdns_ZoneFormatter $formatter;

    protected function setUp(): void
    {
        $this->formatter = new Modules_Powerdns_ZoneFormatter('ns1.example.com');
    }

    // ── A records ───────────────────────────────────────

    public function testARecord(): void
    {
        $zone = [
            'name' => 'example.com.',
            'rr'   => [
                ['host' => 'www.example.com.', 'type' => 'A', 'value' => '192.0.2.1', 'ttl' => 3600],
            ],
        ];

        $rrsets = $this->formatter->pleskToRrsets($zone);

        // Should have one rrset (no SOA since soa key is missing)
        $aRrset = $this->findRrset($rrsets, 'www.example.com.', 'A');
        $this->assertNotNull($aRrset);
        $this->assertSame('REPLACE', $aRrset['changetype']);
        $this->assertSame(3600, $aRrset['ttl']);
        $this->assertCount(1, $aRrset['records']);
        $this->assertSame('192.0.2.1', $aRrset['records'][0]['content']);
        $this->assertFalse($aRrset['records'][0]['disabled']);
    }

    public function testMultipleARecordsSameHost(): void
    {
        $zone = [
            'name' => 'example.com.',
            'rr'   => [
                ['host' => 'example.com.', 'type' => 'A', 'value' => '192.0.2.1', 'ttl' => 3600],
                ['host' => 'example.com.', 'type' => 'A', 'value' => '192.0.2.2', 'ttl' => 3600],
            ],
        ];

        $rrsets = $this->formatter->pleskToRrsets($zone);

        $aRrset = $this->findRrset($rrsets, 'example.com.', 'A');
        $this->assertNotNull($aRrset);
        $this->assertCount(2, $aRrset['records']);
    }

    // ── AAAA records ────────────────────────────────────

    public function testAAAARecord(): void
    {
        $zone = [
            'name' => 'example.com.',
            'rr'   => [
                ['host' => 'example.com.', 'type' => 'AAAA', 'value' => '2001:db8::1', 'ttl' => 3600],
            ],
        ];

        $rrsets = $this->formatter->pleskToRrsets($zone);
        $rrset = $this->findRrset($rrsets, 'example.com.', 'AAAA');
        $this->assertNotNull($rrset);
        $this->assertSame('2001:db8::1', $rrset['records'][0]['content']);
    }

    // ── CNAME records ───────────────────────────────────

    public function testCnameTrailingDot(): void
    {
        $zone = [
            'name' => 'example.com.',
            'rr'   => [
                ['host' => 'www.example.com.', 'type' => 'CNAME', 'value' => 'example.com', 'ttl' => 3600],
            ],
        ];

        $rrsets = $this->formatter->pleskToRrsets($zone);
        $rrset = $this->findRrset($rrsets, 'www.example.com.', 'CNAME');
        $this->assertNotNull($rrset);
        // Should add trailing dot
        $this->assertSame('example.com.', $rrset['records'][0]['content']);
    }

    // ── MX records ──────────────────────────────────────

    public function testMxRecord(): void
    {
        $zone = [
            'name' => 'example.com.',
            'rr'   => [
                ['host' => 'example.com.', 'type' => 'MX', 'value' => '10 mail.example.com', 'ttl' => 3600],
            ],
        ];

        $rrsets = $this->formatter->pleskToRrsets($zone);
        $rrset = $this->findRrset($rrsets, 'example.com.', 'MX');
        $this->assertNotNull($rrset);
        // Target should get trailing dot
        $this->assertSame('10 mail.example.com.', $rrset['records'][0]['content']);
    }

    // ── NS records ──────────────────────────────────────

    public function testNsRecord(): void
    {
        $zone = [
            'name' => 'example.com.',
            'rr'   => [
                ['host' => 'example.com.', 'type' => 'NS', 'value' => 'ns1.example.com', 'ttl' => 86400],
            ],
        ];

        $rrsets = $this->formatter->pleskToRrsets($zone);
        $rrset = $this->findRrset($rrsets, 'example.com.', 'NS');
        $this->assertNotNull($rrset);
        $this->assertSame('ns1.example.com.', $rrset['records'][0]['content']);
    }

    // ── TXT records ─────────────────────────────────────

    public function testTxtRecordUnquoted(): void
    {
        $zone = [
            'name' => 'example.com.',
            'rr'   => [
                ['host' => 'example.com.', 'type' => 'TXT', 'value' => 'v=spf1 include:example.com ~all', 'ttl' => 3600],
            ],
        ];

        $rrsets = $this->formatter->pleskToRrsets($zone);
        $rrset = $this->findRrset($rrsets, 'example.com.', 'TXT');
        $this->assertNotNull($rrset);
        // Should be wrapped in quotes
        $this->assertSame('"v=spf1 include:example.com ~all"', $rrset['records'][0]['content']);
    }

    public function testTxtRecordAlreadyQuoted(): void
    {
        $zone = [
            'name' => 'example.com.',
            'rr'   => [
                ['host' => 'example.com.', 'type' => 'TXT', 'value' => '"v=spf1 ~all"', 'ttl' => 3600],
            ],
        ];

        $rrsets = $this->formatter->pleskToRrsets($zone);
        $rrset = $this->findRrset($rrsets, 'example.com.', 'TXT');
        // Already quoted — should not double-quote
        $this->assertSame('"v=spf1 ~all"', $rrset['records'][0]['content']);
    }

    public function testTxtRecordUnbalancedQuote(): void
    {
        $zone = [
            'name' => 'example.com.',
            'rr'   => [
                ['host' => 'example.com.', 'type' => 'TXT', 'value' => '"v=spf1 ~all', 'ttl' => 3600],
            ],
        ];

        $rrsets = $this->formatter->pleskToRrsets($zone);
        $rrset = $this->findRrset($rrsets, 'example.com.', 'TXT');
        // Unbalanced quote should be stripped and re-quoted properly
        $this->assertSame('"v=spf1 ~all"', $rrset['records'][0]['content']);
    }

    public function testTxtRecordMultiString(): void
    {
        $zone = [
            'name' => 'example.com.',
            'rr'   => [
                ['host' => 'example.com.', 'type' => 'TXT', 'value' => '"part one" "part two"', 'ttl' => 3600],
            ],
        ];

        $rrsets = $this->formatter->pleskToRrsets($zone);
        $rrset = $this->findRrset($rrsets, 'example.com.', 'TXT');
        // Multi-string TXT (starts and ends with quote) should pass through as-is
        $this->assertSame('"part one" "part two"', $rrset['records'][0]['content']);
    }

    // ── SRV records ─────────────────────────────────────

    public function testSrvRecord(): void
    {
        $zone = [
            'name' => 'example.com.',
            'rr'   => [
                ['host' => '_sip._tcp.example.com.', 'type' => 'SRV', 'value' => '10 5 5060 sip.example.com', 'ttl' => 3600],
            ],
        ];

        $rrsets = $this->formatter->pleskToRrsets($zone);
        $rrset = $this->findRrset($rrsets, '_sip._tcp.example.com.', 'SRV');
        $this->assertNotNull($rrset);
        // Target should get trailing dot
        $this->assertSame('10 5 5060 sip.example.com.', $rrset['records'][0]['content']);
    }

    // ── CAA records ─────────────────────────────────────

    public function testCaaRecord(): void
    {
        $zone = [
            'name' => 'example.com.',
            'rr'   => [
                ['host' => 'example.com.', 'type' => 'CAA', 'value' => '0 issue "letsencrypt.org"', 'ttl' => 3600],
            ],
        ];

        $rrsets = $this->formatter->pleskToRrsets($zone);
        $rrset = $this->findRrset($rrsets, 'example.com.', 'CAA');
        $this->assertNotNull($rrset);
        $this->assertSame('0 issue "letsencrypt.org"', $rrset['records'][0]['content']);
    }

    // ── SOA record ──────────────────────────────────────

    public function testSoaUsesConfiguredPrimaryNs(): void
    {
        $formatter = new Modules_Powerdns_ZoneFormatter('ns1.mypdns.com');
        $zone = [
            'name' => 'example.com.',
            'soa'  => [
                'email'   => 'admin@example.com',
                'ttl'     => 86400,
                'serial'  => 2024010101,
                'refresh' => 10800,
                'retry'   => 3600,
                'expire'  => 604800,
                'minimum' => 3600,
            ],
            'rr'   => [],
        ];

        $rrsets = $formatter->pleskToRrsets($zone);
        $soaRrset = $this->findRrset($rrsets, 'example.com.', 'SOA');
        $this->assertNotNull($soaRrset);
        $this->assertSame(86400, $soaRrset['ttl']);

        $content = $soaRrset['records'][0]['content'];
        // SOA should use configured primary NS, not a placeholder
        $this->assertStringStartsWith('ns1.mypdns.com.', $content);
        $this->assertStringContainsString('admin.example.com.', $content);
        $this->assertStringContainsString('2024010101', $content);
    }

    public function testSoaFallsBackToFirstNsRecord(): void
    {
        // No explicit primaryNs configured
        $formatter = new Modules_Powerdns_ZoneFormatter(null);
        $zone = [
            'name' => 'example.com.',
            'soa'  => [
                'email'   => 'admin@example.com',
                'serial'  => 2024010101,
            ],
            'rr'   => [
                ['host' => 'example.com.', 'type' => 'NS', 'value' => 'ns1.hosting.com', 'ttl' => 86400],
                ['host' => 'example.com.', 'type' => 'NS', 'value' => 'ns2.hosting.com', 'ttl' => 86400],
            ],
        ];

        $rrsets = $formatter->pleskToRrsets($zone);
        $soaRrset = $this->findRrset($rrsets, 'example.com.', 'SOA');
        $this->assertNotNull($soaRrset);

        $content = $soaRrset['records'][0]['content'];
        // Should pick the first NS record as primary
        $this->assertStringStartsWith('ns1.hosting.com.', $content);
    }

    public function testSoaEmptyEmailUsesDefault(): void
    {
        $formatter = new Modules_Powerdns_ZoneFormatter('ns1.example.com');
        $zone = [
            'name' => 'example.com.',
            'soa'  => [
                'email'  => '',
                'serial' => 2024010101,
            ],
            'rr'   => [],
        ];

        $rrsets = $formatter->pleskToRrsets($zone);
        $soaRrset = $this->findRrset($rrsets, 'example.com.', 'SOA');
        $this->assertNotNull($soaRrset);

        $content = $soaRrset['records'][0]['content'];
        // Empty email should fall back to hostmaster.zone
        $this->assertStringContainsString('hostmaster.example.com.', $content);
    }

    public function testSoaEmptySerialUsesGenerated(): void
    {
        $formatter = new Modules_Powerdns_ZoneFormatter('ns1.example.com');
        $zone = [
            'name' => 'example.com.',
            'soa'  => [
                'email'  => 'admin@example.com',
                'serial' => '',
            ],
            'rr'   => [],
        ];

        $rrsets = $formatter->pleskToRrsets($zone);
        $soaRrset = $this->findRrset($rrsets, 'example.com.', 'SOA');
        $this->assertNotNull($soaRrset);

        $content = $soaRrset['records'][0]['content'];
        // Empty serial should generate a date-based serial (YYYYMMDD01)
        $this->assertMatchesRegularExpression('/\d{10}/', $content);
    }

    // ── Unsupported types ───────────────────────────────

    public function testUnsupportedTypeSkipped(): void
    {
        $zone = [
            'name' => 'example.com.',
            'rr'   => [
                ['host' => 'example.com.', 'type' => 'UNKNOWNTYPE', 'value' => 'test', 'ttl' => 3600],
                ['host' => 'example.com.', 'type' => 'A', 'value' => '1.2.3.4', 'ttl' => 3600],
            ],
        ];

        $rrsets = $this->formatter->pleskToRrsets($zone);

        // Should only have the A record, unknown type is skipped
        $this->assertNull($this->findRrset($rrsets, 'example.com.', 'UNKNOWNTYPE'));
        $this->assertNotNull($this->findRrset($rrsets, 'example.com.', 'A'));
    }

    // ── Host without trailing dot ───────────────────────

    public function testHostGetsTrailingDot(): void
    {
        $zone = [
            'name' => 'example.com',
            'rr'   => [
                ['host' => 'www.example.com', 'type' => 'A', 'value' => '1.2.3.4', 'ttl' => 300],
            ],
        ];

        $rrsets = $this->formatter->pleskToRrsets($zone);
        $rrset = $this->findRrset($rrsets, 'www.example.com.', 'A');
        $this->assertNotNull($rrset, 'Host should have trailing dot added');
    }

    // ── Mixed record types ──────────────────────────────

    public function testMixedRecordTypes(): void
    {
        $zone = [
            'name' => 'example.com.',
            'rr'   => [
                ['host' => 'example.com.', 'type' => 'A', 'value' => '1.2.3.4', 'ttl' => 3600],
                ['host' => 'example.com.', 'type' => 'MX', 'value' => '10 mail.example.com', 'ttl' => 3600],
                ['host' => 'example.com.', 'type' => 'TXT', 'value' => 'v=spf1 ~all', 'ttl' => 3600],
                ['host' => 'www.example.com.', 'type' => 'CNAME', 'value' => 'example.com', 'ttl' => 3600],
            ],
        ];

        $rrsets = $this->formatter->pleskToRrsets($zone);

        $this->assertNotNull($this->findRrset($rrsets, 'example.com.', 'A'));
        $this->assertNotNull($this->findRrset($rrsets, 'example.com.', 'MX'));
        $this->assertNotNull($this->findRrset($rrsets, 'example.com.', 'TXT'));
        $this->assertNotNull($this->findRrset($rrsets, 'www.example.com.', 'CNAME'));
    }

    // ── Helper ──────────────────────────────────────────

    private function findRrset(array $rrsets, string $name, string $type): ?array
    {
        foreach ($rrsets as $rrset) {
            if ($rrset['name'] === $name && $rrset['type'] === $type) {
                return $rrset;
            }
        }
        return null;
    }
}
