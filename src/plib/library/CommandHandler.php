<?php

declare(strict_types=1);

// Copyright 2024. All rights reserved.

/**
 * Handles DNS zone commands dispatched by the Plesk backend script.
 *
 * Extracted from powerdns.php to enable unit testing without
 * requiring stdin or Plesk globals.
 */
class Modules_Powerdns_CommandHandler
{
    private Modules_Powerdns_Client $client;
    private Modules_Powerdns_ZoneFormatter $formatter;
    private Modules_Powerdns_Logger $logger;

    /** @var string[] */
    private array $nameservers;
    private bool $dnssec;
    private string $zoneKind;
    private int $ipv6Prefix;

    /**
     * @param string[] $nameservers
     */
    public function __construct(
        Modules_Powerdns_Client $client,
        Modules_Powerdns_ZoneFormatter $formatter,
        Modules_Powerdns_Logger $logger,
        array $nameservers,
        bool $dnssec = false,
        string $zoneKind = 'Native',
        int $ipv6Prefix = 48
    ) {
        $this->client = $client;
        $this->formatter = $formatter;
        $this->logger = $logger;
        $this->nameservers = $nameservers;
        $this->dnssec = $dnssec;
        $this->zoneKind = $zoneKind;
        $this->ipv6Prefix = $ipv6Prefix;
    }

    /**
     * Dispatch a command from Plesk's JSON payload.
     *
     * @param array<string, mixed> $input Decoded JSON from stdin
     */
    public function dispatch(array $input): void
    {
        $command = $input['command'] ?? null;

        if ($command === null) {
            throw new Modules_Powerdns_Exception('No command in input payload');
        }

        switch ($command) {
            case 'create':
            case 'update':
                $this->handleZoneUpdate($input);
                break;

            case 'delete':
                $this->handleZoneDelete($input);
                break;

            case 'createPTRs':
                $this->handleCreatePtrs($input);
                break;

            case 'deletePTRs':
                $this->handleDeletePtrs($input);
                break;

            default:
                $this->logger->warn("Unknown command: {$command} — ignoring");
                break;
        }
    }

    /**
     * Create or update a zone on PowerDNS.
     *
     * @param array<string, mixed> $input
     */
    private function handleZoneUpdate(array $input): void
    {
        $zone = $input['zone'] ?? null;
        if ($zone === null || empty($zone['name'])) {
            throw new Modules_Powerdns_Exception('Zone data missing in update command');
        }

        $zoneName = rtrim($zone['name'], '.') . '.';
        $this->logger->info("Processing zone update: {$zoneName}");

        if (empty($this->nameservers)) {
            $this->logger->err('No nameservers configured — cannot create zone');
            throw new Modules_Powerdns_Exception('Nameservers not configured');
        }

        $rrsets = $this->formatter->pleskToRrsets($zone);

        $existing = $this->client->getZone($zoneName);

        if ($existing === null) {
            $this->logger->info("Zone {$zoneName} does not exist on PowerDNS — creating");
            $this->client->createZone($zoneName, $this->nameservers, $rrsets, $this->dnssec, $this->zoneKind);
        } else {
            $this->client->updateZone($zoneName, $rrsets);
        }

        $this->logger->info("Zone {$zoneName} synced successfully");
    }

    /**
     * Delete a zone from PowerDNS.
     *
     * @param array<string, mixed> $input
     */
    private function handleZoneDelete(array $input): void
    {
        $zone = $input['zone'] ?? null;
        if ($zone === null || empty($zone['name'])) {
            throw new Modules_Powerdns_Exception('Zone data missing in delete command');
        }

        $zoneName = rtrim($zone['name'], '.') . '.';
        $this->logger->info("Processing zone delete: {$zoneName}");

        $existing = $this->client->getZone($zoneName);
        if ($existing === null) {
            $this->logger->warn("Zone {$zoneName} does not exist on PowerDNS — nothing to delete");
            return;
        }

        $this->client->deleteZone($zoneName);
        $this->logger->info("Zone {$zoneName} deleted successfully");
    }

    /**
     * Create PTR records (reverse DNS).
     *
     * @param array<string, mixed> $input
     */
    private function handleCreatePtrs(array $input): void
    {
        $ptr = $input['ptr'] ?? null;
        if ($ptr === null || empty($ptr['ip_address']) || empty($ptr['hostname'])) {
            $this->logger->warn('Incomplete PTR data — skipping');
            return;
        }

        $ip = $ptr['ip_address'];
        $hostname = rtrim($ptr['hostname'], '.') . '.';

        $reverseZone = Modules_Powerdns_ReverseDns::buildReverseZone($ip, $this->ipv6Prefix);
        $ptrName = Modules_Powerdns_ReverseDns::buildPtrName($ip);

        if ($reverseZone === null || $ptrName === null) {
            $this->logger->warn("Cannot build reverse zone for IP: {$ip}");
            return;
        }

        $this->logger->info("Creating PTR: {$ptrName} -> {$hostname}");

        $existing = $this->client->getZone($reverseZone);
        if ($existing === null) {
            if (!empty($this->nameservers)) {
                $this->client->createZone($reverseZone, $this->nameservers);
            } else {
                // Log as error (not warn) so it persists to the admin error log.
                // Unlike forward zones, PTR failures are non-fatal: Plesk does not
                // depend on PTR success to complete the hosting operation.
                $this->logger->err("No nameservers configured — cannot create reverse zone {$reverseZone}");
                return;
            }
        }

        $rrsets = [
            [
                'name' => $ptrName,
                'type' => 'PTR',
                'changetype' => 'REPLACE',
                'ttl' => 3600,
                'records' => [
                    ['content' => $hostname, 'disabled' => false],
                ],
            ],
        ];

        $this->client->updateZone($reverseZone, $rrsets);
    }

    /**
     * Delete PTR records (reverse DNS).
     *
     * @param array<string, mixed> $input
     */
    private function handleDeletePtrs(array $input): void
    {
        $ptr = $input['ptr'] ?? null;
        if ($ptr === null || empty($ptr['ip_address'])) {
            $this->logger->warn('Incomplete PTR delete data — skipping');
            return;
        }

        $ip = $ptr['ip_address'];
        $reverseZone = Modules_Powerdns_ReverseDns::buildReverseZone($ip, $this->ipv6Prefix);
        $ptrName = Modules_Powerdns_ReverseDns::buildPtrName($ip);

        if ($reverseZone === null || $ptrName === null) {
            $this->logger->warn("Cannot build reverse zone for IP: {$ip}");
            return;
        }

        $this->logger->info("Deleting PTR: {$ptrName}");

        $existing = $this->client->getZone($reverseZone);
        if ($existing === null) {
            $this->logger->warn("Reverse zone {$reverseZone} not found — nothing to delete");
            return;
        }

        $rrsets = [
            [
                'name' => $ptrName,
                'type' => 'PTR',
                'changetype' => 'DELETE',
                'records' => [],
            ],
        ];

        $this->client->updateZone($reverseZone, $rrsets);
    }
}
