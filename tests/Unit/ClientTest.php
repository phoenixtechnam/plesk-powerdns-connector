<?php

// Copyright 2024. All rights reserved.

declare(strict_types=1);

/**
 * Unit tests for the PowerDNS API Client.
 *
 * Uses Guzzle's MockHandler to simulate HTTP responses without
 * requiring a running PowerDNS instance.
 */

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class ClientTest extends TestCase
{
    private Modules_Powerdns_Logger $logger;

    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        pm_Settings::reset();
        $this->history = [];
        $this->logger = new Modules_Powerdns_Logger();
    }

    private function createClient(array $responses): Modules_Powerdns_Client
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        $httpClient = new GuzzleClient([
            'handler'    => $stack,
            'base_uri'   => 'http://localhost:8081/api/v1/',
            'http_errors' => false,
        ]);

        return new Modules_Powerdns_Client(
            'http://localhost:8081',
            'test-api-key',
            'localhost',
            $httpClient,
            $this->logger
        );
    }

    // ── Connection ──────────────────────────────────────

    public function testTestConnectionSuccess(): void
    {
        $client = $this->createClient([
            new Response(200, [], json_encode(['type' => 'Server', 'id' => 'localhost'])),
        ]);

        $result = $client->testConnection();
        $this->assertSame('Server', $result['type']);
    }

    public function testTestConnectionAuthFailure(): void
    {
        $client = $this->createClient([
            new Response(401, [], json_encode(['error' => 'Unauthorized'])),
        ]);

        $this->expectException(Modules_Powerdns_Exception::class);
        $client->testConnection();
    }

    // ── Zone CRUD ───────────────────────────────────────

    public function testListZones(): void
    {
        $zones = [
            ['name' => 'example.com.', 'kind' => 'Native'],
            ['name' => 'test.org.', 'kind' => 'Native'],
        ];
        $client = $this->createClient([
            new Response(200, [], json_encode($zones)),
        ]);

        $result = $client->listZones();
        $this->assertCount(2, $result);
        $this->assertSame('example.com.', $result[0]['name']);
    }

    public function testGetZoneFound(): void
    {
        $client = $this->createClient([
            new Response(200, [], json_encode(['name' => 'example.com.', 'rrsets' => []])),
        ]);

        $result = $client->getZone('example.com.');
        $this->assertNotNull($result);
        $this->assertSame('example.com.', $result['name']);
    }

    public function testGetZoneNotFound(): void
    {
        $client = $this->createClient([
            new Response(404, [], json_encode(['error' => 'Could not find domain'])),
        ]);

        $result = $client->getZone('nonexistent.com.');
        $this->assertNull($result);
    }

    public function testCreateZone(): void
    {
        $client = $this->createClient([
            new Response(201, [], json_encode(['name' => 'example.com.', 'id' => 'example.com.'])),
        ]);

        $result = $client->createZone('example.com', ['ns1.example.com', 'ns2.example.com']);
        $this->assertSame('example.com.', $result['name']);

        // Verify trailing dots in request body
        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('example.com.', $body['name']);
        $this->assertSame(['ns1.example.com.', 'ns2.example.com.'], $body['nameservers']);
    }

    public function testCreateZoneFiltersNsAndSoaFromRrsets(): void
    {
        $client = $this->createClient([
            new Response(201, [], json_encode(['name' => 'example.com.'])),
        ]);

        $rrsets = [
            ['name' => 'example.com.', 'type' => 'NS', 'changetype' => 'REPLACE', 'ttl' => 86400, 'records' => []],
            ['name' => 'example.com.', 'type' => 'SOA', 'changetype' => 'REPLACE', 'ttl' => 86400, 'records' => []],
            ['name' => 'example.com.', 'type' => 'A', 'changetype' => 'REPLACE', 'ttl' => 3600, 'records' => [['content' => '1.2.3.4', 'disabled' => false]]],
        ];

        $client->createZone('example.com.', ['ns1.example.com.'], $rrsets);

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        // Only the A record should remain
        $this->assertCount(1, $body['rrsets']);
        $this->assertSame('A', $body['rrsets'][0]['type']);
    }

    public function testUpdateZone(): void
    {
        $client = $this->createClient([
            new Response(204, []),
        ]);

        // Should not throw
        $client->updateZone('example.com.', [
            ['name' => 'example.com.', 'type' => 'A', 'changetype' => 'REPLACE', 'ttl' => 3600, 'records' => []],
        ]);

        $this->assertSame('PATCH', $this->history[0]['request']->getMethod());
    }

    public function testDeleteZone(): void
    {
        $client = $this->createClient([
            new Response(204, []),
        ]);

        $client->deleteZone('example.com.');
        $this->assertSame('DELETE', $this->history[0]['request']->getMethod());
    }

    // ── Retry logic ─────────────────────────────────────

    public function testNoRetryOnClientError(): void
    {
        $client = $this->createClient([
            new Response(400, [], json_encode(['error' => 'Bad Request'])),
        ]);

        try {
            $client->testConnection();
            $this->fail('Expected exception not thrown');
        } catch (Modules_Powerdns_Exception $e) {
            $this->assertSame(400, $e->getHttpCode());
        }

        // Should have made only 1 request (no retries)
        $this->assertCount(1, $this->history);
    }

    public function testNoRetryOnConflict409(): void
    {
        $client = $this->createClient([
            new Response(409, [], json_encode(['error' => 'Conflict'])),
        ]);

        try {
            $client->createZone('example.com.', ['ns1.example.com.']);
            $this->fail('Expected exception not thrown');
        } catch (Modules_Powerdns_Exception $e) {
            $this->assertSame(409, $e->getHttpCode());
        }

        // 409 should NOT be retried — only 1 request
        $this->assertCount(1, $this->history);
    }

    public function testRetryOnServerError(): void
    {
        $client = $this->createClient([
            new Response(500, [], json_encode(['error' => 'Internal Server Error'])),
            new Response(500, [], json_encode(['error' => 'Internal Server Error'])),
            new Response(200, [], json_encode(['type' => 'Server'])),
        ]);

        $result = $client->testConnection();
        $this->assertSame('Server', $result['type']);
        // 1 initial + 2 retries = 3 requests
        $this->assertCount(3, $this->history);
    }

    // ── Trailing dot in URLs ────────────────────────────

    public function testEnsureTrailingDotOnZoneName(): void
    {
        $client = $this->createClient([
            new Response(204, []),
        ]);

        $client->updateZone('example.com', []);
        $uri = (string) $this->history[0]['request']->getUri();
        $this->assertStringContainsString('example.com.', $uri);
    }

    // ── DNSSEC ──────────────────────────────────────────

    public function testEnableDnssec(): void
    {
        $client = $this->createClient([
            new Response(201, [], json_encode(['id' => 0, 'keytype' => 'ksk', 'active' => true, 'ds' => ['....']])),
        ]);

        $result = $client->enableDnssec('example.com.');
        $this->assertSame('ksk', $result['keytype']);

        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('ksk', $body['keytype']);
        $this->assertTrue($body['active']);
    }

    public function testDisableDnssec(): void
    {
        $client = $this->createClient([
            // getCryptokeys response
            new Response(200, [], json_encode([['id' => 1, 'keytype' => 'ksk', 'active' => true]])),
            // DELETE cryptokey response
            new Response(204, []),
        ]);

        $client->disableDnssec('example.com.');

        $this->assertCount(2, $this->history);
        $this->assertSame('GET', $this->history[0]['request']->getMethod());
        $this->assertSame('DELETE', $this->history[1]['request']->getMethod());
    }

    // ── Exception details ───────────────────────────────

    public function testExceptionContainsHttpCodeAndError(): void
    {
        $client = $this->createClient([
            new Response(422, [], json_encode(['error' => 'RRset example.com./A already exists'])),
        ]);

        try {
            $client->createZone('example.com.', ['ns1.example.com.']);
            $this->fail('Expected exception');
        } catch (Modules_Powerdns_Exception $e) {
            $this->assertSame(422, $e->getHttpCode());
            $this->assertSame('RRset example.com./A already exists', $e->getPdnsError());
        }
    }
}
