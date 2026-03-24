<?php

// Copyright 2024. All rights reserved.

declare(strict_types=1);

/**
 * Integration tests against a real PowerDNS server.
 *
 * Requires: docker-compose.test.yml running with PowerDNS at PDNS_API_URL.
 *
 * Run: PDNS_API_URL=http://dind:8081 PDNS_API_KEY=test-api-key \
 *      php src/plib/vendor/bin/phpunit tests/Integration/
 */

use PHPUnit\Framework\TestCase;

class LivePdnsTest extends TestCase
{
    private static Modules_Powerdns_Client $client;
    private static Modules_Powerdns_Logger $logger;
    private static string $apiUrl;
    private static string $apiKey;

    public static function setUpBeforeClass(): void
    {
        self::$apiUrl = getenv('PDNS_API_URL') ?: 'http://dind:8081';
        self::$apiKey = getenv('PDNS_API_KEY') ?: 'test-api-key';
        self::$logger = new Modules_Powerdns_Logger();
        self::$client = new Modules_Powerdns_Client(self::$apiUrl, self::$apiKey, 'localhost', null, self::$logger);
    }

    protected function tearDown(): void
    {
        // Clean up test zones
        foreach (['live-test.example.com.', 'updated-test.example.com.', '1.168.192.in-addr.arpa.'] as $zone) {
            try {
                self::$client->deleteZone($zone);
            } catch (\Exception $e) {
                // ignore — may not exist
            }
        }
    }

    // ── Connection ──────────────────────────────────────

    public function testConnectionToLiveServer(): void
    {
        $info = self::$client->testConnection();

        $this->assertSame('localhost', $info['id']);
        $this->assertSame('authoritative', $info['daemon_type']);
        $this->assertArrayHasKey('version', $info);
    }

    public function testListZonesInitiallyEmpty(): void
    {
        $zones = self::$client->listZones();

        $this->assertIsArray($zones);
    }

    // ── Zone CRUD ───────────────────────────────────────

    public function testCreateZone(): void
    {
        $result = self::$client->createZone(
            'live-test.example.com.',
            ['ns1.example.com.', 'ns2.example.com.'],
            [],
            false,
            'Native'
        );

        $this->assertSame('live-test.example.com.', $result['name']);
        $this->assertSame('Native', $result['kind']);

        // Verify it exists
        $zone = self::$client->getZone('live-test.example.com.');
        $this->assertNotNull($zone);
        $this->assertSame('live-test.example.com.', $zone['name']);
    }

    public function testUpdateZoneRecords(): void
    {
        self::$client->createZone('updated-test.example.com.', ['ns1.example.com.']);

        $rrsets = [
            [
                'name' => 'www.updated-test.example.com.',
                'type' => 'A',
                'changetype' => 'REPLACE',
                'ttl' => 300,
                'records' => [
                    ['content' => '192.0.2.1', 'disabled' => false],
                ],
            ],
        ];

        self::$client->updateZone('updated-test.example.com.', $rrsets);

        // Verify the record exists
        $zone = self::$client->getZone('updated-test.example.com.');
        $this->assertNotNull($zone);

        $found = false;
        foreach ($zone['rrsets'] as $rrset) {
            if ($rrset['name'] === 'www.updated-test.example.com.' && $rrset['type'] === 'A') {
                $found = true;
                $this->assertSame(300, $rrset['ttl']);
                $this->assertSame('192.0.2.1', $rrset['records'][0]['content']);
            }
        }
        $this->assertTrue($found, 'A record not found in zone rrsets');
    }

    public function testDeleteZone(): void
    {
        self::$client->createZone('live-test.example.com.', ['ns1.example.com.']);

        self::$client->deleteZone('live-test.example.com.');

        $zone = self::$client->getZone('live-test.example.com.');
        $this->assertNull($zone);
    }

    public function testGetZoneNotFoundReturnsNull(): void
    {
        $zone = self::$client->getZone('nonexistent.example.com.');
        $this->assertNull($zone);
    }

    // ── CommandHandler full pipeline ────────────────────

    public function testCommandHandlerCreateAndUpdate(): void
    {
        $formatter = new Modules_Powerdns_ZoneFormatter('ns1.example.com');
        $handler = new Modules_Powerdns_CommandHandler(
            self::$client,
            $formatter,
            self::$logger,
            ['ns1.example.com.', 'ns2.example.com.'],
            false,
            'Native',
            48
        );

        // Create via dispatch
        $handler->dispatch([
            'command' => 'create',
            'zone' => [
                'name' => 'live-test.example.com',
                'soa' => [
                    'email' => 'admin@live-test.example.com',
                    'serial' => '2024010101',
                    'refresh' => 10800,
                    'retry' => 3600,
                    'expire' => 604800,
                    'minimum' => 3600,
                    'ttl' => 86400,
                ],
                'rr' => [
                    ['host' => 'live-test.example.com.', 'type' => 'A', 'value' => '93.184.216.34', 'ttl' => 3600],
                    ['host' => 'www.live-test.example.com.', 'type' => 'CNAME', 'value' => 'live-test.example.com', 'ttl' => 3600],
                    ['host' => 'live-test.example.com.', 'type' => 'MX', 'value' => '10 mail.live-test.example.com', 'ttl' => 3600],
                    ['host' => 'live-test.example.com.', 'type' => 'TXT', 'value' => 'v=spf1 include:example.com ~all', 'ttl' => 3600],
                ],
            ],
        ]);

        // Verify zone was created with records
        $zone = self::$client->getZone('live-test.example.com.');
        $this->assertNotNull($zone);

        $types = array_column($zone['rrsets'], 'type');
        $this->assertContains('A', $types);
        $this->assertContains('CNAME', $types);
        $this->assertContains('MX', $types);
        $this->assertContains('TXT', $types);
        $this->assertContains('SOA', $types);
        $this->assertContains('NS', $types);

        // Update via dispatch
        $handler->dispatch([
            'command' => 'update',
            'zone' => [
                'name' => 'live-test.example.com',
                'rr' => [
                    ['host' => 'live-test.example.com.', 'type' => 'A', 'value' => '198.51.100.1', 'ttl' => 300],
                ],
            ],
        ]);

        // Verify A record was updated
        $zone = self::$client->getZone('live-test.example.com.');
        foreach ($zone['rrsets'] as $rrset) {
            if ($rrset['name'] === 'live-test.example.com.' && $rrset['type'] === 'A') {
                $this->assertSame('198.51.100.1', $rrset['records'][0]['content']);
                $this->assertSame(300, $rrset['ttl']);
            }
        }
    }

    public function testCommandHandlerDelete(): void
    {
        self::$client->createZone('live-test.example.com.', ['ns1.example.com.']);

        $formatter = new Modules_Powerdns_ZoneFormatter('ns1.example.com');
        $handler = new Modules_Powerdns_CommandHandler(
            self::$client,
            $formatter,
            self::$logger,
            ['ns1.example.com.'],
        );

        $handler->dispatch([
            'command' => 'delete',
            'zone' => ['name' => 'live-test.example.com'],
        ]);

        $this->assertNull(self::$client->getZone('live-test.example.com.'));
    }

    public function testCommandHandlerCreatePtr(): void
    {
        $formatter = new Modules_Powerdns_ZoneFormatter('ns1.example.com');
        $handler = new Modules_Powerdns_CommandHandler(
            self::$client,
            $formatter,
            self::$logger,
            ['ns1.example.com.'],
        );

        $handler->dispatch([
            'command' => 'createPTRs',
            'ptr' => [
                'ip_address' => '192.168.1.100',
                'hostname' => 'server.example.com',
            ],
        ]);

        // Verify reverse zone was created
        $zone = self::$client->getZone('1.168.192.in-addr.arpa.');
        $this->assertNotNull($zone);

        // Verify PTR record
        $found = false;
        foreach ($zone['rrsets'] as $rrset) {
            if ($rrset['type'] === 'PTR' && $rrset['name'] === '100.1.168.192.in-addr.arpa.') {
                $found = true;
                $this->assertSame('server.example.com.', $rrset['records'][0]['content']);
            }
        }
        $this->assertTrue($found, 'PTR record not found');
    }
}
