<?php
// Copyright 2024. All rights reserved.

/**
 * Custom DNS Backend Script for Plesk.
 *
 * Plesk invokes this script via stdin with a JSON payload whenever
 * a DNS zone is created, updated, or deleted.  The script translates
 * the zone data and pushes it to the PowerDNS API.
 *
 * Exit codes:
 *   0   — success
 *   255 — failure (Plesk logs the error)
 */

// Plesk autoloader and module context
pm_Loader::registerAutoload();
pm_Context::init('powerdns');

$logger = new Modules_Powerdns_Logger();

// ── Guard: extension must be enabled ────────────────────────
$enabled = pm_Settings::get('enabled');
if (!$enabled) {
    $logger->info('PowerDNS extension is disabled — skipping');
    exit(0);
}

// ── Read JSON command from stdin ────────────────────────────
$stdin = file_get_contents('php://stdin');
if (empty($stdin)) {
    $logger->err('Empty input received from Plesk');
    exit(255);
}

$input = json_decode($stdin, true);
if ($input === null) {
    $logger->err('Invalid JSON input: ' . json_last_error_msg());
    exit(255);
}

$command = $input['command'] ?? null;

if ($command === null) {
    $logger->err('No command in input payload');
    exit(255);
}

// ── Build PowerDNS client ───────────────────────────────────
$apiUrl   = pm_Settings::get('apiUrl');
$apiKey   = pm_Settings::get('apiKey');
$serverId = pm_Settings::get('serverId', 'localhost');

if (empty($apiUrl) || empty($apiKey)) {
    $logger->err('PowerDNS API credentials not configured');
    exit(255);
}

try {
    $client    = new Modules_Powerdns_Client($apiUrl, $apiKey, $serverId);
    $ns1       = pm_Settings::get('ns1', '');
    $formatter = new Modules_Powerdns_ZoneFormatter($ns1);
} catch (\Exception $e) {
    $logger->err('Failed to initialize PowerDNS client: ' . $e->getMessage());
    exit(255);
}

// ── Dispatch command ────────────────────────────────────────
try {
    switch ($command) {
        case 'create':
        case 'update':
            handleZoneUpdate($input, $client, $formatter, $logger);
            break;

        case 'delete':
            handleZoneDelete($input, $client, $logger);
            break;

        case 'createPTRs':
            handleCreatePtrs($input, $client, $logger);
            break;

        case 'deletePTRs':
            handleDeletePtrs($input, $client, $logger);
            break;

        default:
            $logger->warn("Unknown command: {$command} — ignoring");
            break;
    }
} catch (Modules_Powerdns_Exception $e) {
    $logger->err("PowerDNS API error for command '{$command}': " . $e->getMessage());
    exit(255);
} catch (\Exception $e) {
    $logger->err("Unexpected error for command '{$command}': " . $e->getMessage());
    exit(255);
}

exit(0);

// ─────────────────────────────────────────────────────────────
//  Command handlers
// ─────────────────────────────────────────────────────────────

/**
 * Create or update a zone on PowerDNS.
 *
 * If the zone already exists, we PATCH it with the new rrsets.
 * If it doesn't exist, we create it first.
 */
function handleZoneUpdate(
    array $input,
    Modules_Powerdns_Client $client,
    Modules_Powerdns_ZoneFormatter $formatter,
    Modules_Powerdns_Logger $logger
): void {
    $zone = $input['zone'] ?? null;
    if ($zone === null || empty($zone['name'])) {
        throw new Modules_Powerdns_Exception('Zone data missing in update command');
    }

    $zoneName = rtrim($zone['name'], '.') . '.';
    $logger->info("Processing zone update: {$zoneName}");

    // Retrieve configured nameservers
    $ns1 = pm_Settings::get('ns1', '');
    $ns2 = pm_Settings::get('ns2', '');
    $nameservers = array_filter([$ns1, $ns2]);

    if (empty($nameservers)) {
        $logger->err('No nameservers configured — cannot create zone');
        throw new Modules_Powerdns_Exception('Nameservers not configured');
    }

    // Zone options from settings
    $dnssecDefault = (bool) pm_Settings::get('dnssec', '');
    $zoneKind = pm_Settings::get('zoneKind', 'Native');

    // Convert Plesk records to PowerDNS rrsets
    $rrsets = $formatter->pleskToRrsets($zone);

    // Check if zone exists
    $existing = $client->getZone($zoneName);

    if ($existing === null) {
        // Zone doesn't exist — create it with initial rrsets
        $logger->info("Zone {$zoneName} does not exist on PowerDNS — creating");
        $client->createZone($zoneName, $nameservers, $rrsets, $dnssecDefault, $zoneKind);
    } else {
        // Zone exists — update records via PATCH
        $client->updateZone($zoneName, $rrsets);
    }

    $logger->info("Zone {$zoneName} synced successfully");
}

/**
 * Delete a zone from PowerDNS.
 */
function handleZoneDelete(
    array $input,
    Modules_Powerdns_Client $client,
    Modules_Powerdns_Logger $logger
): void {
    $zone = $input['zone'] ?? null;
    if ($zone === null || empty($zone['name'])) {
        throw new Modules_Powerdns_Exception('Zone data missing in delete command');
    }

    $zoneName = rtrim($zone['name'], '.') . '.';
    $logger->info("Processing zone delete: {$zoneName}");

    // Check if zone exists before deleting
    $existing = $client->getZone($zoneName);
    if ($existing === null) {
        $logger->warn("Zone {$zoneName} does not exist on PowerDNS — nothing to delete");
        return;
    }

    $client->deleteZone($zoneName);
    $logger->info("Zone {$zoneName} deleted successfully");
}

/**
 * Create PTR records (reverse DNS).
 *
 * Plesk sends: { "command": "createPTRs", "ptr": { "ip_address": "1.2.3.4", "hostname": "example.com" } }
 */
function handleCreatePtrs(
    array $input,
    Modules_Powerdns_Client $client,
    Modules_Powerdns_Logger $logger
): void {
    $ptr = $input['ptr'] ?? null;
    if ($ptr === null || empty($ptr['ip_address']) || empty($ptr['hostname'])) {
        $logger->warn('Incomplete PTR data — skipping');
        return;
    }

    $ip       = $ptr['ip_address'];
    $hostname = rtrim($ptr['hostname'], '.') . '.';

    // Build reverse zone name from IP
    $reverseZone = buildReverseZone($ip);
    $ptrName     = buildPtrName($ip);

    if ($reverseZone === null || $ptrName === null) {
        $logger->warn("Cannot build reverse zone for IP: {$ip}");
        return;
    }

    $logger->info("Creating PTR: {$ptrName} -> {$hostname}");

    // Ensure the reverse zone exists
    $existing = $client->getZone($reverseZone);
    if ($existing === null) {
        $ns1 = pm_Settings::get('ns1', '');
        $ns2 = pm_Settings::get('ns2', '');
        $nameservers = array_filter([$ns1, $ns2]);
        if (!empty($nameservers)) {
            $client->createZone($reverseZone, $nameservers);
        } else {
            $logger->warn("No nameservers configured — cannot create reverse zone {$reverseZone}");
            return;
        }
    }

    $rrsets = [
        [
            'name'       => $ptrName,
            'type'       => 'PTR',
            'changetype' => 'REPLACE',
            'ttl'        => 3600,
            'records'    => [
                ['content' => $hostname, 'disabled' => false],
            ],
        ],
    ];

    $client->updateZone($reverseZone, $rrsets);
}

/**
 * Delete PTR records (reverse DNS).
 */
function handleDeletePtrs(
    array $input,
    Modules_Powerdns_Client $client,
    Modules_Powerdns_Logger $logger
): void {
    $ptr = $input['ptr'] ?? null;
    if ($ptr === null || empty($ptr['ip_address'])) {
        $logger->warn('Incomplete PTR delete data — skipping');
        return;
    }

    $ip          = $ptr['ip_address'];
    $reverseZone = buildReverseZone($ip);
    $ptrName     = buildPtrName($ip);

    if ($reverseZone === null || $ptrName === null) {
        $logger->warn("Cannot build reverse zone for IP: {$ip}");
        return;
    }

    $logger->info("Deleting PTR: {$ptrName}");

    $existing = $client->getZone($reverseZone);
    if ($existing === null) {
        $logger->warn("Reverse zone {$reverseZone} not found — nothing to delete");
        return;
    }

    $rrsets = [
        [
            'name'       => $ptrName,
            'type'       => 'PTR',
            'changetype' => 'DELETE',
            'records'    => [],
        ],
    ];

    $client->updateZone($reverseZone, $rrsets);
}

// ─────────────────────────────────────────────────────────────
//  Helpers
// ─────────────────────────────────────────────────────────────

/**
 * Build the reverse DNS zone name from an IP address.
 *
 * IPv4: "192.168.1.100" → "1.168.192.in-addr.arpa."
 * IPv6: "2001:0db8:1234:5678:9abc:def0:1234:5678" → "8.7.6.5.4.3.2.1.8.b.d.0.1.0.0.2.ip6.arpa."
 *        (uses /48 boundary for zone — first 12 nibbles)
 */
function buildReverseZone(string $ip): ?string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $octets = explode('.', $ip);
        $reversed = array_reverse(array_slice($octets, 0, 3));
        return implode('.', $reversed) . '.in-addr.arpa.';
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $expanded = expandIpv6($ip);
        if ($expanded === null) {
            return null;
        }
        // Use /48 boundary (first 12 nibbles) for the zone
        $nibbles = str_replace(':', '', $expanded);
        $zoneNibbles = array_reverse(str_split(substr($nibbles, 0, 12)));
        return implode('.', $zoneNibbles) . '.ip6.arpa.';
    }

    return null;
}

/**
 * Build the full PTR record name from an IP address.
 *
 * IPv4: "192.168.1.100" → "100.1.168.192.in-addr.arpa."
 * IPv6: "2001:db8::1"   → "1.0.0.0...8.b.d.0.1.0.0.2.ip6.arpa."
 */
function buildPtrName(string $ip): ?string
{
    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $octets = array_reverse(explode('.', $ip));
        return implode('.', $octets) . '.in-addr.arpa.';
    }

    if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $expanded = expandIpv6($ip);
        if ($expanded === null) {
            return null;
        }
        $nibbles = str_replace(':', '', $expanded);
        $reversed = array_reverse(str_split($nibbles));
        return implode('.', $reversed) . '.ip6.arpa.';
    }

    return null;
}

/**
 * Expand an IPv6 address to its full 32-nibble representation.
 * e.g., "2001:db8::1" → "2001:0db8:0000:0000:0000:0000:0000:0001"
 */
function expandIpv6(string $ip): ?string
{
    $packed = @inet_pton($ip);
    if ($packed === false) {
        return null;
    }

    $hex = bin2hex($packed);
    // Insert colons every 4 characters
    return implode(':', str_split($hex, 4));
}
