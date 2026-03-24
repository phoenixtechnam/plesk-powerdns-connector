<?php

// Copyright 2024. All rights reserved.

declare(strict_types=1);

/**
 * Unit tests for the NotificationService webhook notifications.
 */

use GuzzleHttp\Client as GuzzleClient;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class NotificationServiceTest extends TestCase
{
    /** @var array<int, array{request: \Psr\Http\Message\RequestInterface}> */
    private array $history = [];

    protected function setUp(): void
    {
        pm_Settings::reset();
        $this->history = [];
    }

    private function createService(array $responses, string $webhookUrl = 'https://hooks.example.com/dns'): Modules_Powerdns_NotificationService
    {
        $mock = new MockHandler($responses);
        $stack = HandlerStack::create($mock);
        $stack->push(Middleware::history($this->history));

        $httpClient = new GuzzleClient(['handler' => $stack, 'http_errors' => false]);
        $logger = new Modules_Powerdns_Logger();

        return new Modules_Powerdns_NotificationService($webhookUrl, $logger, $httpClient);
    }

    public function testSyncFailureSendsWebhook(): void
    {
        $service = $this->createService([new Response(200, [])]);

        $service->notifySyncFailure('example.com.', 'Connection refused', 'create');

        $this->assertCount(1, $this->history);
        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('sync_failure', $body['event']);
        $this->assertSame('example.com.', $body['zone']);
        $this->assertSame('create', $body['command']);
        $this->assertSame('Connection refused', $body['error']);
        $this->assertSame('plesk-powerdns-connector', $body['source']);
    }

    public function testBulkSyncCompleteSendsWebhook(): void
    {
        $service = $this->createService([new Response(200, [])]);

        $service->notifyBulkSyncComplete(10, 2, ['bad.com', 'fail.org']);

        $this->assertCount(1, $this->history);
        $body = json_decode((string) $this->history[0]['request']->getBody(), true);
        $this->assertSame('bulk_sync_complete', $body['event']);
        $this->assertSame(10, $body['synced']);
        $this->assertSame(2, $body['failed']);
        $this->assertSame(['bad.com', 'fail.org'], $body['failed_domains']);
    }

    public function testEmptyWebhookUrlSkipsSending(): void
    {
        $service = $this->createService([], '');

        $service->notifySyncFailure('example.com.', 'error');

        $this->assertCount(0, $this->history);
    }

    public function testHttpUrlIsBlocked(): void
    {
        $service = $this->createService([], 'http://internal.server/hook');

        $service->notifySyncFailure('example.com.', 'error');

        // Non-HTTPS URLs should be rejected
        $this->assertCount(0, $this->history);
    }

    public function testWebhookFailureDoesNotThrow(): void
    {
        $service = $this->createService([new Response(500, [], 'Server Error')]);

        // Should not throw — webhook failures are non-fatal
        $service->notifySyncFailure('example.com.', 'error');

        $this->assertCount(1, $this->history);
    }

    public function testWebhookSetsCorrectHeaders(): void
    {
        $service = $this->createService([new Response(200, [])]);

        $service->notifySyncFailure('test.com.', 'err');

        $request = $this->history[0]['request'];
        $this->assertSame('application/json', $request->getHeaderLine('Content-Type'));
        $this->assertStringContainsString('Plesk-PowerDNS-Connector', $request->getHeaderLine('User-Agent'));
    }
}
