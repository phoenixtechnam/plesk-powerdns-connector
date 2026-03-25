<?php

// Copyright 2024. All rights reserved.

/**
 * Plesk Event Listener for supplementary DNS sync events.
 *
 * The custom DNS backend (powerdns.php) handles most zone events, but
 * some events (particularly PTR/IP changes and domain alias changes)
 * may not always be forwarded through the backend script.
 *
 * This listener catches those events and triggers the appropriate
 * PowerDNS API calls.
 */
class Modules_Powerdns_EventListener implements EventListener
{
    /**
     * Filter which events we want to handle.
     * Required since Plesk 18.0.71 for performance.
     *
     * @return string[]
     */
    public function filterActions()
    {
        return [
            // IP assignment changes (PTR records)
            'ip_address_create',
            'ip_address_update',
            'ip_address_delete',

            // Domain alias lifecycle (may need DNS sync)
            'domain_alias_create',
            'domain_alias_delete',
        ];
    }

    /**
     * @param string $objectType
     * @param int $objectId
     * @param string $action
     * @param array $oldValues
     * @param array $newValues
     */
    public function handleEvent($objectType, $objectId, $action, $oldValues, $newValues): void
    {
        // Only act if the extension is enabled and configured
        $enabled = pm_Settings::get('enabled');
        if (!$enabled) {
            return;
        }

        $apiUrl = pm_Settings::get('apiUrl');
        $apiKey = pm_Settings::get('apiKey');
        if (empty($apiUrl) || empty($apiKey)) {
            return;
        }

        try {
            $logger = new Modules_Powerdns_Logger();
            $serverId = pm_Settings::get('serverId', 'localhost') ?: 'localhost';
            $client = new Modules_Powerdns_Client($apiUrl, $apiKey, $serverId);
            $ns1 = pm_Settings::get('ns1', '') ?: '';
            $ns2 = pm_Settings::get('ns2', '') ?: '';
            $formatter = new Modules_Powerdns_ZoneFormatter($ns1);
            $ipv6Prefix = (int) (pm_Settings::get('ipv6Prefix', '48') ?: '48');

            $handler = new Modules_Powerdns_CommandHandler(
                $client,
                $formatter,
                $logger,
                array_filter([$ns1, $ns2]),
                (bool) pm_Settings::get('dnssec', ''),
                pm_Settings::get('zoneKind', 'Native') ?: 'Native',
                $ipv6Prefix
            );

            switch ($action) {
                case 'ip_address_create':
                case 'ip_address_update':
                    $this->handlePtrCreate($handler, $newValues, $logger);
                    break;

                case 'ip_address_delete':
                    $this->handlePtrDelete($handler, $oldValues, $logger);
                    break;

                case 'domain_alias_create':
                    $this->handleAliasCreate($handler, $newValues, $logger);
                    break;

                case 'domain_alias_delete':
                    $this->handleAliasDelete($handler, $oldValues, $logger);
                    break;
            }
        } catch (\Exception $e) {
            $logger = new Modules_Powerdns_Logger();
            $logger->err("EventListener error ({$action}): " . $e->getMessage());
        }
    }

    /**
     * @param array $values
     */
    private function handlePtrCreate(
        Modules_Powerdns_CommandHandler $handler,
        array $values,
        Modules_Powerdns_Logger $logger
    ): void {
        $ip = $values['ip_address'] ?? $values['IP Address'] ?? null;
        $hostname = $values['hostname'] ?? $values['Hostname'] ?? $values['Domain Name'] ?? null;

        if ($ip === null) {
            return;
        }

        // If no hostname in the event, try to resolve it from the domain
        if ($hostname === null) {
            $logger->info("PTR event for {$ip} without hostname — skipping");
            return;
        }

        $logger->info("EventListener: creating PTR for {$ip} -> {$hostname}");
        $handler->dispatch([
            'command' => 'createPTRs',
            'ptr' => ['ip_address' => $ip, 'hostname' => $hostname],
        ]);
    }

    /**
     * @param array $values
     */
    private function handlePtrDelete(
        Modules_Powerdns_CommandHandler $handler,
        array $values,
        Modules_Powerdns_Logger $logger
    ): void {
        $ip = $values['ip_address'] ?? $values['IP Address'] ?? null;

        if ($ip === null) {
            return;
        }

        $logger->info("EventListener: deleting PTR for {$ip}");
        $handler->dispatch([
            'command' => 'deletePTRs',
            'ptr' => ['ip_address' => $ip],
        ]);
    }

    /**
     * @param array $values
     */
    private function handleAliasCreate(
        Modules_Powerdns_CommandHandler $handler,
        array $values,
        Modules_Powerdns_Logger $logger
    ): void {
        $aliasName = $values['Domain Alias Name'] ?? $values['domain_alias_name'] ?? null;
        if ($aliasName === null) {
            return;
        }

        $logger->info("EventListener: domain alias created — {$aliasName}");
        // The alias zone will be synced via the custom backend when DNS is set up.
        // No additional action needed here — this is a future extension point.
    }

    /**
     * @param array $values
     */
    private function handleAliasDelete(
        Modules_Powerdns_CommandHandler $handler,
        array $values,
        Modules_Powerdns_Logger $logger
    ): void {
        $aliasName = $values['Domain Alias Name'] ?? $values['domain_alias_name'] ?? null;
        if ($aliasName === null) {
            return;
        }

        $logger->info("EventListener: domain alias deleted — deleting zone {$aliasName}");
        try {
            $handler->dispatch([
                'command' => 'delete',
                'zone' => ['name' => $aliasName],
            ]);
        } catch (\Exception $e) {
            $logger->warn("Could not delete alias zone {$aliasName}: " . $e->getMessage());
        }
    }
}

return new Modules_Powerdns_EventListener();
