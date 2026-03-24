<?php

declare(strict_types=1);

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

// ── Build PowerDNS client and command handler ───────────────
$apiUrl   = pm_Settings::get('apiUrl');
$apiKey   = pm_Settings::get('apiKey');
$serverId = pm_Settings::get('serverId', 'localhost') ?? 'localhost';

if (empty($apiUrl) || empty($apiKey)) {
    $logger->err('PowerDNS API credentials not configured');
    exit(255);
}

try {
    $client    = new Modules_Powerdns_Client($apiUrl, $apiKey, $serverId);
    $ns1       = pm_Settings::get('ns1', '');
    $ns2       = pm_Settings::get('ns2', '');
    $formatter = new Modules_Powerdns_ZoneFormatter($ns1);

    $handler = new Modules_Powerdns_CommandHandler(
        $client,
        $formatter,
        $logger,
        array_filter([$ns1, $ns2]),
        (bool) pm_Settings::get('dnssec', ''),
        pm_Settings::get('zoneKind', 'Native') ?? 'Native',
        (int) (pm_Settings::get('ipv6Prefix', '48') ?? '48')
    );
} catch (\Exception $e) {
    $logger->err('Failed to initialize PowerDNS client: ' . $e->getMessage());
    exit(255);
}

// ── Dispatch command ────────────────────────────────────────
try {
    $handler->dispatch($input);
} catch (Modules_Powerdns_Exception $e) {
    $command = $input['command'] ?? 'unknown';
    $logger->err("PowerDNS API error for command '{$command}': " . $e->getMessage());
    exit(255);
} catch (\Exception $e) {
    $command = $input['command'] ?? 'unknown';
    $logger->err("Unexpected error for command '{$command}': " . $e->getMessage());
    exit(255);
}

exit(0);
