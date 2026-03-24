<?php

declare(strict_types=1);

// Copyright 2024. All rights reserved.

/**
 * Post-installation script.
 *
 * Registers the custom DNS backend with Plesk so that all zone
 * changes are forwarded to our powerdns.php script.
 *
 * Safety check: refuses to install if another DNS backend extension
 * is active, to prevent silently overriding it.
 */

pm_Loader::registerAutoload();
pm_Context::init('powerdns');

// ── Conflict detection ──────────────────────────────────
// Plesk only supports one custom DNS backend at a time. Installing
// ours would silently override any existing backend (e.g., Slave DNS
// Manager, Route53, DigitalOcean DNS). Check and refuse if found.

$conflictingExtensions = [
    'slave-dns-manager'  => 'Slave DNS Manager',
    'route53'            => 'Amazon Route 53',
    'digitalocean-dns'   => 'DigitalOcean DNS',
    'cloudflare-dns'     => 'CloudFlare DNS',
    'vultr-dns'          => 'Vultr DNS',
];

foreach ($conflictingExtensions as $extId => $extName) {
    try {
        // pm_ApiCli --info returns extension details including status.
        // We only block if the extension is both installed AND enabled.
        // A disabled extension has already unregistered its DNS backend,
        // so it's safe to install alongside.
        $output = pm_ApiCli::callSilent('extension', ['--info', $extId]);

        $isInstalled = stripos($output, 'not installed') === false;
        $isEnabled = stripos($output, 'active') !== false
            || stripos($output, 'enabled') !== false;

        if ($isInstalled && $isEnabled) {
            echo "ERROR: Cannot install PowerDNS connector — the '{$extName}' extension ({$extId}) is currently active.\n";
            echo "Plesk only supports one custom DNS backend at a time.\n";
            echo "Please disable or uninstall '{$extName}' first, then retry.\n";
            exit(1);
        }
    } catch (\Exception $e) {
        // Extension not installed or API call failed — safe to continue
    }
}

// ── Register the custom DNS backend ─────────────────────
try {
    pm_ApiCli::call('server_dns', [
        '--enable-custom-backend',
        '/usr/local/psa/bin/extension --exec powerdns powerdns.php',
    ]);

    echo "PowerDNS custom DNS backend registered successfully.\n";
} catch (pm_Exception $e) {
    echo 'Failed to register custom DNS backend: ' . $e->getMessage() . "\n";
    exit(1);
}

// ── Initialize default settings ─────────────────────────
$defaults = [
    'enabled'    => '',
    'apiUrl'     => '',
    'apiKey'     => '',
    'serverId'   => 'localhost',
    'ns1'        => '',
    'ns2'        => '',
    'zoneKind'   => 'Native',
    'ipv6Prefix' => '48',
    'dnssec'     => '',
];

foreach ($defaults as $key => $defaultValue) {
    if (pm_Settings::get($key) === null) {
        pm_Settings::set($key, $defaultValue);
    }
}

exit(0);
