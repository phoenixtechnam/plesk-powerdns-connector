<?php

declare(strict_types=1);

// Copyright 2024. All rights reserved.

/**
 * PowerDNS Authoritative Server API client.
 *
 * Wraps the PDNS HTTP API (v1) with typed methods for zone
 * and record management.  Uses Guzzle for HTTP transport.
 */
class Modules_Powerdns_Client
{
    /** @var \GuzzleHttp\Client */
    private $http;

    /** @var string e.g. "localhost" */
    private $serverId;

    /** @var Modules_Powerdns_Logger */
    private $logger;

    /** Maximum number of retry attempts for transient errors (5xx, 429). */
    private const MAX_RETRIES = 2;

    /**
     * Delay between retries in seconds.
     *
     * Note: uses blocking sleep() — acceptable for the CLI backend script
     * (called by Plesk per zone event), but will block the PHP process for
     * up to MAX_RETRIES * RETRY_DELAY_SECONDS = 6 seconds on repeated failures.
     */
    private const RETRY_DELAY_SECONDS = 3;

    /**
     * @param string $apiUrl   Base URL, e.g. "http://pdns.example.com:8081"
     * @param string $apiKey   X-API-Key value
     * @param string $serverId Server identifier (usually "localhost")
     */
    public function __construct(
        string $apiUrl,
        string $apiKey,
        string $serverId = 'localhost',
        ?\GuzzleHttp\Client $httpClient = null,
        ?Modules_Powerdns_Logger $logger = null
    ) {
        $this->serverId = $serverId;
        $this->logger = $logger ?? new Modules_Powerdns_Logger();

        $this->http = $httpClient ?? new \GuzzleHttp\Client([
            'base_uri' => rtrim($apiUrl, '/') . '/api/v1/',
            'headers'  => [
                'X-API-Key'    => $apiKey,
                'Content-Type' => 'application/json',
                'Accept'       => 'application/json',
            ],
            'timeout'         => 30,
            'connect_timeout' => 10,
            'http_errors'     => false,
        ]);
    }

    // ──────────────────────────────────────────────
    //  Connection
    // ──────────────────────────────────────────────

    /**
     * Verify credentials by listing zones.
     *
     * @throws Modules_Powerdns_Exception on auth or connectivity failure
     */
    public function testConnection(): array
    {
        return $this->request('GET', "servers/{$this->serverId}");
    }

    // ──────────────────────────────────────────────
    //  Zone operations
    // ──────────────────────────────────────────────

    /**
     * @return array<int, array> List of zone objects
     */
    public function listZones(): array
    {
        return $this->request('GET', "servers/{$this->serverId}/zones");
    }

    /**
     * @param string $zoneName Canonical zone name (with trailing dot)
     * @return array|null       Zone data or null if not found
     */
    public function getZone(string $zoneName): ?array
    {
        try {
            return $this->request('GET', "servers/{$this->serverId}/zones/{$zoneName}");
        } catch (Modules_Powerdns_Exception $e) {
            if ($e->getHttpCode() === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * Create a new zone on PowerDNS.
     *
     * @param string   $zoneName    Canonical name (trailing dot)
     * @param string[] $nameservers NS records (trailing dots)
     * @param array    $rrsets      Optional initial rrsets
     * @param bool     $dnssec      Enable DNSSEC for this zone
     * @param string   $kind        Zone kind: 'Native' (default) or 'Primary' (sends NOTIFY to secondaries)
     */
    public function createZone(
        string $zoneName,
        array $nameservers,
        array $rrsets = [],
        bool $dnssec = false,
        string $kind = 'Native'
    ): array {
        // PowerDNS 4.9+ rejects requests containing both 'nameservers' and
        // NS-type rrsets.  When rrsets are provided, strip NS records from
        // the rrsets and let 'nameservers' handle NS creation.  Also strip
        // SOA rrsets — PDNS auto-generates SOA on zone creation.
        $filteredRrsets = array_values(array_filter($rrsets, function (array $rr): bool {
            $type = $rr['type'] ?? '';
            return !in_array($type, ['NS', 'SOA'], true);
        }));

        $payload = [
            'name'        => Modules_Powerdns_DnsUtils::ensureTrailingDot($zoneName),
            'kind'        => $kind,
            'nameservers' => array_map([Modules_Powerdns_DnsUtils::class, 'ensureTrailingDot'], $nameservers),
            'rrsets'      => $filteredRrsets,
        ];

        $this->logger->info("Creating zone: {$zoneName} (kind={$kind})");
        $result = $this->request('POST', "servers/{$this->serverId}/zones", $payload);

        // DNSSEC is enabled via a separate cryptokey POST, not a zone-level flag.
        // PDNS 4.9 accepted 'dnssec' in the zone POST but PDNS 5.0 removed it.
        // Using the cryptokey approach works on both versions.
        if ($dnssec) {
            $this->enableDnssec($zoneName);
        }

        return $result;
    }

    /**
     * Enable DNSSEC on an existing zone by creating a cryptokey.
     *
     * PowerDNS 4.9+ requires posting a cryptokey to enable DNSSEC,
     * rather than setting a zone-level 'dnssec' flag via PUT.
     *
     * @param string $zoneName  Canonical name
     * @param string $algorithm Key algorithm (default: ecdsap256sha256)
     * @return array            Created cryptokey data (includes DS records)
     */
    public function enableDnssec(string $zoneName, string $algorithm = 'ecdsap256sha256'): array
    {
        $zoneName = Modules_Powerdns_DnsUtils::ensureTrailingDot($zoneName);
        $this->logger->info("Enabling DNSSEC for zone: {$zoneName} (algo={$algorithm})");
        return $this->request('POST', "servers/{$this->serverId}/zones/{$zoneName}/cryptokeys", [
            'keytype'   => 'ksk',
            'active'    => true,
            'algorithm' => $algorithm,
        ]);
    }

    /**
     * Disable DNSSEC on an existing zone by deactivating all cryptokeys.
     *
     * @param string $zoneName Canonical name
     */
    public function disableDnssec(string $zoneName): void
    {
        $zoneName = Modules_Powerdns_DnsUtils::ensureTrailingDot($zoneName);
        $this->logger->info("Disabling DNSSEC for zone: {$zoneName}");
        $keys = $this->getCryptokeys($zoneName);
        foreach ($keys as $key) {
            if (!empty($key['id'])) {
                $this->request(
                    'DELETE',
                    "servers/{$this->serverId}/zones/{$zoneName}/cryptokeys/{$key['id']}"
                );
            }
        }
    }

    /**
     * Retrieve DNSSEC cryptokeys for a zone (needed to get DS records).
     *
     * @param string $zoneName Canonical name
     * @return array Cryptokey objects
     */
    public function getCryptokeys(string $zoneName): array
    {
        $zoneName = Modules_Powerdns_DnsUtils::ensureTrailingDot($zoneName);
        return $this->request('GET', "servers/{$this->serverId}/zones/{$zoneName}/cryptokeys");
    }

    /**
     * Update records in an existing zone (PATCH with rrsets).
     *
     * @param string $zoneName Canonical name
     * @param array  $rrsets   Array of rrset objects with changetype REPLACE/DELETE
     */
    public function updateZone(string $zoneName, array $rrsets): void
    {
        $zoneName = Modules_Powerdns_DnsUtils::ensureTrailingDot($zoneName);
        $payload = ['rrsets' => $rrsets];

        $this->logger->info("Updating zone: {$zoneName} (" . count($rrsets) . ' rrsets)');
        $this->request('PATCH', "servers/{$this->serverId}/zones/{$zoneName}", $payload);
    }

    /**
     * Delete a zone from PowerDNS.
     */
    public function deleteZone(string $zoneName): void
    {
        $zoneName = Modules_Powerdns_DnsUtils::ensureTrailingDot($zoneName);
        $this->logger->info("Deleting zone: {$zoneName}");
        $this->request('DELETE', "servers/{$this->serverId}/zones/{$zoneName}");
    }

    // ──────────────────────────────────────────────
    //  HTTP helpers
    // ──────────────────────────────────────────────

    /**
     * @throws Modules_Powerdns_Exception
     */
    private function request(string $method, string $uri, ?array $body = null): array
    {
        $options = [];
        if ($body !== null) {
            $options['json'] = $body;
        }

        $lastException = null;

        for ($attempt = 0; $attempt <= self::MAX_RETRIES; $attempt++) {
            if ($attempt > 0) {
                $this->logger->warn("Retry {$attempt} for {$method} {$uri}");
                sleep(self::RETRY_DELAY_SECONDS);
            }

            try {
                $response = $this->http->request($method, $uri, $options);
            } catch (\GuzzleHttp\Exception\GuzzleException $e) {
                $lastException = new Modules_Powerdns_Exception(
                    "PowerDNS API connection error: {$e->getMessage()}",
                    null,
                    null,
                    $e
                );
                continue;
            }

            $statusCode = $response->getStatusCode();
            $rawBody = (string) $response->getBody();

            // 204 No Content is a valid success (PATCH, DELETE)
            if ($statusCode === 204) {
                return [];
            }

            $decoded = json_decode($rawBody, true) ?? [];

            if ($statusCode >= 200 && $statusCode < 300) {
                return $decoded;
            }

            $errorMsg = $decoded['error'] ?? $rawBody;

            // Do not retry client errors (4xx) except 429 (rate limit)
            if ($statusCode >= 400 && $statusCode < 500 && $statusCode !== 429) {
                throw new Modules_Powerdns_Exception(
                    "PowerDNS API error ({$statusCode}): {$errorMsg}",
                    $statusCode,
                    $errorMsg
                );
            }

            $lastException = new Modules_Powerdns_Exception(
                "PowerDNS API error ({$statusCode}): {$errorMsg}",
                $statusCode,
                $errorMsg
            );
        }

        /** @phpstan-ignore identical.alwaysFalse (defensive guard for safety) */
        if ($lastException === null) {
            throw new Modules_Powerdns_Exception('Unknown PowerDNS API error');
        }

        throw $lastException;
    }
}
