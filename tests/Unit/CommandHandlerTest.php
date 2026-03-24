<?php

// Copyright 2024. All rights reserved.

declare(strict_types=1);

/**
 * Unit tests for the CommandHandler.
 *
 * Uses Guzzle's MockHandler to test the full command dispatch pipeline
 * without requiring a running PowerDNS instance.
 */

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class CommandHandlerTest extends TestCase
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

    private function createHandler(array $responses, array $overrides = []): Modules_Powerdns_CommandHandler
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        $httpClient = new GuzzleClient([
            'handler' => $stack,
            'base_uri' => 'http://localhost:8081/api/v1/',
            'http_errors' => false,
        ]);

        $client = new Modules_Powerdns_Client(
            'http://localhost:8081',
            'test-api-key',
            'localhost',
            $httpClient,
            $this->logger
        );

        $formatter = new Modules_Powerdns_ZoneFormatter($overrides['ns1'] ?? 'ns1.example.com');

        return new Modules_Powerdns_CommandHandler(
            $client,
            $formatter,
            $this->logger,
            $overrides['nameservers'] ?? ['ns1.example.com.', 'ns2.example.com.'],
            $overrides['dnssec'] ?? false,
            $overrides['zoneKind'] ?? 'Native',
            $overrides['ipv6Prefix'] ?? 48
        );
    }

    // ── Dispatch ────────────────────────────────────────

    public function testDispatchThrowsOnMissingCommand(): void
    {
        $handler = $this->createHandler([]);

        $this->expectException(Modules_Powerdns_Exception::class);
        $this->expectExceptionMessage('No command in input payload');
        $handler->dispatch([]);
    }

    public function testDispatchUnknownCommandDoesNotThrow(): void
    {
        $handler = $this->createHandler([]);
        $handler->dispatch(['command' => 'unknownCommand']);
        // Should not throw, just warn
        $this->assertTrue(true);
    }

    // ── Zone Create ─────────────────────────────────────

    public function testCreateZoneWhenNotExists(): void
    {
        $handler = $this->createHandler([
            // getZone returns 404
            new Response(404, [], json_encode(['error' => 'Could not find domain'])),
            // createZone returns 201
            new Response(201, [], json_encode(['name' => 'example.com.'])),
        ]);

        $handler->dispatch([
            'command' => 'create',
            'zone' => [
                'name' => 'example.com',
                'rr' => [
                    ['host' => 'example.com.', 'type' => 'A', 'value' => '1.2.3.4', 'ttl' => 3600],
                ],
            ],
        ]);

        $this->assertCount(2, $this->history);
        $this->assertSame('GET', $this->history[0]['request']->getMethod());
        $this->assertSame('POST', $this->history[1]['request']->getMethod());
    }

    public function testUpdateZoneWhenExists(): void
    {
        $handler = $this->createHandler([
            // getZone returns 200 (exists)
            new Response(200, [], json_encode(['name' => 'example.com.'])),
            // updateZone returns 204
            new Response(204, []),
        ]);

        $handler->dispatch([
            'command' => 'update',
            'zone' => [
                'name' => 'example.com.',
                'rr' => [
                    ['host' => 'example.com.', 'type' => 'A', 'value' => '1.2.3.4', 'ttl' => 3600],
                ],
            ],
        ]);

        $this->assertCount(2, $this->history);
        $this->assertSame('GET', $this->history[0]['request']->getMethod());
        $this->assertSame('PATCH', $this->history[1]['request']->getMethod());
    }

    public function testCreateZoneMissingData(): void
    {
        $handler = $this->createHandler([]);

        $this->expectException(Modules_Powerdns_Exception::class);
        $this->expectExceptionMessage('Zone data missing');
        $handler->dispatch(['command' => 'create', 'zone' => null]);
    }

    public function testCreateZoneNoNameserversThrows(): void
    {
        $handler = $this->createHandler(
            [new Response(404, [], json_encode(['error' => 'Not found']))],
            ['nameservers' => []]
        );

        $this->expectException(Modules_Powerdns_Exception::class);
        $this->expectExceptionMessage('Nameservers not configured');
        $handler->dispatch([
            'command' => 'create',
            'zone' => ['name' => 'example.com', 'rr' => []],
        ]);
    }

    // ── Zone Delete ─────────────────────────────────────

    public function testDeleteZone(): void
    {
        $handler = $this->createHandler([
            // getZone returns 200
            new Response(200, [], json_encode(['name' => 'example.com.'])),
            // deleteZone returns 204
            new Response(204, []),
        ]);

        $handler->dispatch([
            'command' => 'delete',
            'zone' => ['name' => 'example.com.'],
        ]);

        $this->assertCount(2, $this->history);
        $this->assertSame('DELETE', $this->history[1]['request']->getMethod());
    }

    public function testDeleteZoneNotExistsIsNoop(): void
    {
        $handler = $this->createHandler([
            new Response(404, [], json_encode(['error' => 'Not found'])),
        ]);

        $handler->dispatch([
            'command' => 'delete',
            'zone' => ['name' => 'example.com.'],
        ]);

        // Only 1 GET request, no DELETE
        $this->assertCount(1, $this->history);
    }

    // ── PTR Create ──────────────────────────────────────

    public function testCreatePtrWithExistingReverseZone(): void
    {
        $handler = $this->createHandler([
            // getZone (reverse zone exists)
            new Response(200, [], json_encode(['name' => '1.168.192.in-addr.arpa.'])),
            // updateZone (add PTR)
            new Response(204, []),
        ]);

        $handler->dispatch([
            'command' => 'createPTRs',
            'ptr' => ['ip_address' => '192.168.1.100', 'hostname' => 'server.example.com'],
        ]);

        $this->assertCount(2, $this->history);
        $this->assertSame('PATCH', $this->history[1]['request']->getMethod());
    }

    public function testCreatePtrCreatesReverseZone(): void
    {
        $handler = $this->createHandler([
            // getZone returns 404 (reverse zone doesn't exist)
            new Response(404, [], json_encode(['error' => 'Not found'])),
            // createZone
            new Response(201, [], json_encode(['name' => '1.168.192.in-addr.arpa.'])),
            // updateZone (add PTR)
            new Response(204, []),
        ]);

        $handler->dispatch([
            'command' => 'createPTRs',
            'ptr' => ['ip_address' => '192.168.1.100', 'hostname' => 'server.example.com'],
        ]);

        $this->assertCount(3, $this->history);
        $this->assertSame('POST', $this->history[1]['request']->getMethod());
    }

    public function testCreatePtrIncompletDataSkips(): void
    {
        $handler = $this->createHandler([]);
        $handler->dispatch(['command' => 'createPTRs', 'ptr' => ['ip_address' => '1.2.3.4']]);
        // No HTTP requests
        $this->assertCount(0, $this->history);
    }

    // ── PTR Delete ──────────────────────────────────────

    public function testDeletePtr(): void
    {
        $handler = $this->createHandler([
            // getZone (reverse zone exists)
            new Response(200, [], json_encode(['name' => '1.168.192.in-addr.arpa.'])),
            // updateZone (delete PTR)
            new Response(204, []),
        ]);

        $handler->dispatch([
            'command' => 'deletePTRs',
            'ptr' => ['ip_address' => '192.168.1.100'],
        ]);

        $this->assertCount(2, $this->history);

        $body = json_decode((string) $this->history[1]['request']->getBody(), true);
        $this->assertSame('DELETE', $body['rrsets'][0]['changetype']);
    }

    public function testDeletePtrReverseZoneNotExistsIsNoop(): void
    {
        $handler = $this->createHandler([
            new Response(404, [], json_encode(['error' => 'Not found'])),
        ]);

        $handler->dispatch([
            'command' => 'deletePTRs',
            'ptr' => ['ip_address' => '192.168.1.100'],
        ]);

        // Only 1 GET, no PATCH
        $this->assertCount(1, $this->history);
    }

    // ── IPv6 prefix ─────────────────────────────────────

    public function testCreatePtrIpv6UsesConfiguredPrefix(): void
    {
        $handler = $this->createHandler([
            // getZone for /64 reverse zone
            new Response(200, [], json_encode(['name' => '8.7.6.5.4.3.2.1.8.b.d.0.1.0.0.2.ip6.arpa.'])),
            // updateZone
            new Response(204, []),
        ], ['ipv6Prefix' => 64]);

        $handler->dispatch([
            'command' => 'createPTRs',
            'ptr' => ['ip_address' => '2001:db8:1234:5678::1', 'hostname' => 'server.example.com'],
        ]);

        // The GET request URL should contain the /64 reverse zone
        $uri = (string) $this->history[0]['request']->getUri();
        $this->assertStringContainsString('8.7.6.5.4.3.2.1.8.b.d.0.1.0.0.2.ip6.arpa.', $uri);
    }
}
