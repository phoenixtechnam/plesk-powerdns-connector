<?php

declare(strict_types=1);

// Copyright 2024. All rights reserved.

/**
 * Post-installation script.
 *
 * Registers the custom DNS backend with Plesk so that all zone
 * changes are forwarded to our powerdns.php script.
 */

pm_Loader::registerAutoload();
pm_Context::init('powerdns');

try {
    // Register the custom DNS backend.
    // Plesk will invoke this command for every zone create/update/delete.
    // The --enable-custom-backend flag expects a single command string
    // that Plesk will execute for each zone operation.
    // Plesk's server_dns utility treats the next argument as the full
    // command to invoke, so it must be a single string.
    pm_ApiCli::call('server_dns', [
        '--enable-custom-backend',
        '/usr/local/psa/bin/extension --exec powerdns powerdns.php',
    ]);

    echo "PowerDNS custom DNS backend registered successfully.\n";
} catch (pm_Exception $e) {
    echo 'Failed to register custom DNS backend: ' . $e->getMessage() . "\n";
    exit(1);
}

// Initialize default settings if not already set
$defaults = [
    'enabled'  => '',
    'apiUrl'   => '',
    'apiKey'   => '',
    'serverId' => 'localhost',
    'ns1'      => '',
    'ns2'      => '',
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
