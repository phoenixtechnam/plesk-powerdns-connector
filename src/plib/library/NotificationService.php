<?php

declare(strict_types=1);

// Copyright 2024. All rights reserved.

/**
 * Sends notifications when DNS sync operations fail.
 *
 * Supports webhook notifications via HTTP POST. The webhook URL
 * is configured in the admin settings. When empty, notifications
 * are silently skipped.
 */
class Modules_Powerdns_NotificationService
{
    private Modules_Powerdns_Logger $logger;
    private string $webhookUrl;
    private \GuzzleHttp\Client $httpClient;

    public function __construct(
        string $webhookUrl = '',
        ?Modules_Powerdns_Logger $logger = null,
        ?\GuzzleHttp\Client $httpClient = null
    ) {
        $this->webhookUrl = $webhookUrl;
        $this->logger = $logger ?? new Modules_Powerdns_Logger();
        $this->httpClient = $httpClient ?? new \GuzzleHttp\Client([
            'timeout'         => 10,
            'connect_timeout' => 5,
            'http_errors'     => false,
        ]);
    }

    /**
     * Notify about a sync failure.
     *
     * @param string $zoneName Domain or zone that failed
     * @param string $error    Error message
     * @param string $command  Command that failed (create, update, delete, etc.)
     */
    public function notifySyncFailure(string $zoneName, string $error, string $command = 'sync'): void
    {
        if ($this->webhookUrl === '' || !str_starts_with($this->webhookUrl, 'https://')) {
            return;
        }

        $payload = [
            'event'     => 'sync_failure',
            'zone'      => $zoneName,
            'command'    => $command,
            'error'     => $error,
            'timestamp' => date('c'),
            'source'    => 'plesk-powerdns-connector',
        ];

        $this->sendWebhook($payload);
    }

    /**
     * Notify about bulk sync results.
     *
     * @param int      $synced  Number of zones synced
     * @param int      $failed  Number of zones that failed
     * @param string[] $errors  Domain names that failed
     */
    public function notifyBulkSyncComplete(int $synced, int $failed, array $errors = []): void
    {
        if ($this->webhookUrl === '' || !str_starts_with($this->webhookUrl, 'https://')) {
            return;
        }

        $payload = [
            'event'          => 'bulk_sync_complete',
            'synced'         => $synced,
            'failed'         => $failed,
            'failed_domains' => $errors,
            'timestamp'      => date('c'),
            'source'         => 'plesk-powerdns-connector',
        ];

        $this->sendWebhook($payload);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function sendWebhook(array $payload): void
    {
        try {
            $response = $this->httpClient->request('POST', $this->webhookUrl, [
                'json' => $payload,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'User-Agent'   => 'Plesk-PowerDNS-Connector/1.0',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            if ($statusCode >= 400) {
                $this->logger->warn("Webhook notification failed (HTTP {$statusCode}): {$this->webhookUrl}");
            }
        } catch (\Exception $e) {
            // Webhook failures should never block DNS operations
            $this->logger->warn('Webhook notification error: ' . $e->getMessage());
        }
    }
}
