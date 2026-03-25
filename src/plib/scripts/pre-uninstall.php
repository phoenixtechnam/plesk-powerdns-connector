<?php
// Copyright 2024. All rights reserved.

/**
 * Pre-uninstallation script.
 *
 * Unregisters the custom DNS backend from Plesk.
 * Does NOT delete zones from PowerDNS (preserves DNS data).
 */

pm_Loader::registerAutoload();
pm_Context::init('powerdns');

try {
    pm_ApiCli::call('server_dns', ['--disable-custom-backend']);
    echo "PowerDNS custom DNS backend unregistered successfully.\n";
} catch (pm_Exception $e) {
    echo 'Warning: failed to unregister custom DNS backend: ' . $e->getMessage() . "\n";
    // Don't block uninstallation on this error
}

exit(0);
