<?php

// Copyright 2024. All rights reserved.
// Note: strict_types intentionally omitted — this script is invoked
// by Plesk's loader which may pass non-strict types.

/**
 * Custom DNS Backend Script for Plesk.
 *
 * Plesk invokes this script via stdin with a JSON payload whenever
 * a DNS zone is created, updated, or deleted.  The payload may be
 * a single command object or an array of command objects.
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

// ── Read JSON from stdin ────────────────────────────────────
$stdin = file_get_contents('php://stdin');
if (empty($stdin)) {
    $logger->err('Empty input received from Plesk');
    exit(255);
}

$data = json_decode($stdin, true);
if ($data === null) {
    $logger->err('Invalid JSON input: ' . json_last_error_msg());
    exit(255);
}

// ── Normalise input ─────────────────────────────────────────
// Plesk may send a single command object {"command":...} or an
// array of command objects [{"command":...}, {"command":...}].
// Normalise to always process an array.
if (isset($data['command'])) {
    $commands = [$data];
} elseif (is_array($data) && isset($data[0])) {
    $commands = $data;
} else {
    $logger->info('No command in payload — skipping (DNS may be disabled for this domain)');
    exit(0);
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

// ── Dispatch each command ───────────────────────────────────
$notifier = new Modules_Powerdns_NotificationService(
    pm_Settings::get('webhookUrl', '') ?? '',
    $logger
);

$hasError = false;

foreach ($commands as $input) {
    $command = $input['command'] ?? null;
    if ($command === null) {
        continue;
    }

    try {
        $handler->dispatch($input);
    } catch (\Exception $e) {
        $hasError = true;
        $zoneName = $input['zone']['name'] ?? $input['ptr']['ip_address'] ?? 'unknown';
        $isApiError = $e instanceof Modules_Powerdns_Exception;
        $prefix = $isApiError ? 'PowerDNS API error' : 'Unexpected error';
        $logger->err("{$prefix} for command '{$command}': " . $e->getMessage());
        $notifier->notifySyncFailure($zoneName, $e->getMessage(), $command);
    }
}

exit($hasError ? 255 : 0);
