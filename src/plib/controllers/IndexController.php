<?php

declare(strict_types=1);

// Copyright 2024. All rights reserved.

/**
 * Main controller for the PowerDNS extension admin panel.
 *
 * Provides two tabs:
 *   1. Settings — API connection and nameserver configuration
 *   2. Tools   — Bulk sync, zone status, error log
 */
class IndexController extends pm_Controller_Action
{
    /**
     * Restrict access to Plesk administrators only.
     */
    public function init(): void
    {
        parent::init();

        if (!pm_Session::getClient()->isAdmin()) {
            throw new pm_Exception('Access denied: administrator privileges required');
        }

        $this->view->pageTitle = 'PowerDNS';
    }

    // ──────────────────────────────────────────────
    //  Settings tab
    // ──────────────────────────────────────────────

    public function indexAction(): void
    {
        $form = new Modules_Powerdns_Form_Settings();

        if ($this->getRequest()->isPost()) {
            if ($form->isValid($this->getRequest()->getPost())) {
                $form->process();
                $this->_status->addInfo('Settings saved successfully.');
            } else {
                $this->_status->addError('Please fix the errors below.');
            }
        }

        $this->view->form = $form;
        $this->view->enabled = (bool) pm_Settings::get('enabled');
    }

    // ──────────────────────────────────────────────
    //  Tools tab
    // ──────────────────────────────────────────────

    public function toolsAction(): void
    {
        $this->view->errors = Modules_Powerdns_Logger::getStoredErrors();
    }

    /**
     * Sync all Plesk domains to PowerDNS.
     *
     * Iterates through every domain in Plesk, reads its zone data,
     * and pushes it to PowerDNS.  Used for initial migration or
     * recovery after connectivity issues.
     */
    public function syncAllAction(): void
    {
        if (!$this->getRequest()->isPost()) {
            $this->_redirect('/index');
            return;
        }

        // CSRF protection
        if (!hash_equals((string) pm_Session::getToken(), (string) $this->getRequest()->getPost('token'))) {
            throw new pm_Exception('Invalid request token');
        }

        $apiUrl   = pm_Settings::get('apiUrl');
        $apiKey   = pm_Settings::get('apiKey');
        $serverId = pm_Settings::get('serverId', 'localhost') ?? 'localhost';

        if (empty($apiUrl) || empty($apiKey)) {
            $this->_status->addError('PowerDNS API credentials not configured.');
            $this->_redirect('/index/tools');
            return;
        }

        $ns1 = pm_Settings::get('ns1', '');
        $ns2 = pm_Settings::get('ns2', '');
        $nameservers = array_filter([$ns1, $ns2]);

        if (empty($nameservers)) {
            $this->_status->addError('Nameservers not configured.');
            $this->_redirect('/index/tools');
            return;
        }

        $logger = new Modules_Powerdns_Logger();
        $client = new Modules_Powerdns_Client($apiUrl, $apiKey, $serverId);
        $formatter = new Modules_Powerdns_ZoneFormatter($ns1);

        $handler = new Modules_Powerdns_CommandHandler(
            $client,
            $formatter,
            $logger,
            $nameservers,
            (bool) pm_Settings::get('dnssec', ''),
            pm_Settings::get('zoneKind', 'Native') ?? 'Native',
            (int) (pm_Settings::get('ipv6Prefix', '48') ?? '48')
        );

        $synced  = 0;
        $failed  = 0;
        $errors  = [];

        try {
            $domains = $this->getAllDomains();
        } catch (\Exception $e) {
            $this->_status->addError('Failed to list domains: ' . $e->getMessage());
            $this->_redirect('/index/tools');
            return;
        }

        foreach ($domains as $domainName) {
            try {
                $zoneData = $this->getZoneData($domainName);
                if ($zoneData === null) {
                    continue;
                }

                // Delegate to CommandHandler for consistent create-or-update logic
                $handler->dispatch([
                    'command' => 'update',
                    'zone' => $zoneData,
                ]);

                $synced++;
            } catch (\Exception $e) {
                $failed++;
                $errors[] = $domainName;
                $logger->err("Sync failed for {$domainName}: {$e->getMessage()}");
            }
        }

        // Send webhook notification (skips internally if URL is empty)
        $notifier = new Modules_Powerdns_NotificationService(
            pm_Settings::get('webhookUrl', '') ?? '',
            $logger
        );
        $notifier->notifyBulkSyncComplete($synced, $failed, $errors);

        if ($failed > 0) {
            $displayErrors = array_slice($errors, 0, 10);
            $summary = implode(', ', $displayErrors);
            if ($failed > 10) {
                $summary .= ' and ' . ($failed - 10) . ' more';
            }
            $this->_status->addError(
                "Synced {$synced} zone(s), {$failed} failed: {$summary}. Check the error log for details."
            );
        } else {
            $this->_status->addInfo("Successfully synced {$synced} zone(s) to PowerDNS.");
        }

        $this->_redirect('/index/tools');
    }

    /**
     * Test the connection to PowerDNS and return server info.
     */
    public function healthCheckAction(): void
    {
        if (!$this->getRequest()->isPost()) {
            $this->_redirect('/index/tools');
            return;
        }

        if (!hash_equals((string) pm_Session::getToken(), (string) $this->getRequest()->getPost('token'))) {
            throw new pm_Exception('Invalid request token');
        }

        $apiUrl   = pm_Settings::get('apiUrl');
        $apiKey   = pm_Settings::get('apiKey');
        $serverId = pm_Settings::get('serverId', 'localhost') ?? 'localhost';

        if (empty($apiUrl) || empty($apiKey)) {
            $this->_status->addError('PowerDNS API credentials not configured.');
            $this->_redirect('/index/tools');
            return;
        }

        try {
            $client = new Modules_Powerdns_Client($apiUrl, $apiKey, $serverId);
            $serverInfo = $client->testConnection();
            $version = $serverInfo['version'] ?? 'unknown';
            $zones = $client->listZones();
            $zoneCount = count($zones);

            $this->_status->addInfo(
                "Connected to PowerDNS {$version} (server: {$serverId}, zones: {$zoneCount})."
            );
        } catch (Modules_Powerdns_Exception $e) {
            $this->_status->addError('Health check failed: ' . $e->getMessage());
        } catch (\Exception $e) {
            $logger = new Modules_Powerdns_Logger();
            $logger->err('Health check connection error: ' . $e->getMessage());
            $this->_status->addError('Connection error: unable to reach the PowerDNS server.');
        }

        $this->_redirect('/index/tools');
    }

    /**
     * Preview what bulk sync would change (dry run).
     */
    public function syncPreviewAction(): void
    {
        if (!$this->getRequest()->isPost()) {
            $this->_redirect('/index/tools');
            return;
        }

        if (!hash_equals((string) pm_Session::getToken(), (string) $this->getRequest()->getPost('token'))) {
            throw new pm_Exception('Invalid request token');
        }

        $apiUrl   = pm_Settings::get('apiUrl');
        $apiKey   = pm_Settings::get('apiKey');
        $serverId = pm_Settings::get('serverId', 'localhost') ?? 'localhost';

        if (empty($apiUrl) || empty($apiKey)) {
            $this->_status->addError('PowerDNS API credentials not configured.');
            $this->_redirect('/index/tools');
            return;
        }

        try {
            $client = new Modules_Powerdns_Client($apiUrl, $apiKey, $serverId);
            $domains = $this->getAllDomains();
            $pdnsZones = $client->listZones();

            // Build a set of zone names on PowerDNS
            $pdnsZoneNames = [];
            foreach ($pdnsZones as $zone) {
                $pdnsZoneNames[$zone['name'] ?? ''] = true;
            }

            $toCreate = 0;
            $toUpdate = 0;

            foreach ($domains as $domainName) {
                $zoneName = rtrim($domainName, '.') . '.';
                if (isset($pdnsZoneNames[$zoneName])) {
                    $toUpdate++;
                } else {
                    $toCreate++;
                }
            }

            $total = count($domains);
            $this->_status->addInfo(
                "Sync preview: {$total} domain(s) found. "
                . "{$toCreate} to create, {$toUpdate} to update."
            );
        } catch (\Exception $e) {
            $logger = new Modules_Powerdns_Logger();
            $logger->err('Sync preview error: ' . $e->getMessage());
            $this->_status->addError('Preview failed: unable to complete the preview.');
        }

        $this->_redirect('/index/tools');
    }

    /**
     * Clear stored error log.
     */
    public function clearErrorsAction(): void
    {
        if ($this->getRequest()->isPost()) {
            if (!hash_equals((string) pm_Session::getToken(), (string) $this->getRequest()->getPost('token'))) {
                throw new pm_Exception('Invalid request token');
            }
            Modules_Powerdns_Logger::clearStoredErrors();
            $this->_status->addInfo('Error log cleared.');
        }
        $this->_redirect('/index/tools');
    }

    // ──────────────────────────────────────────────
    //  Helpers
    // ──────────────────────────────────────────────

    /**
     * Get all domain names from Plesk.
     *
     * @return string[]
     */
    private function getAllDomains(): array
    {
        $domains = [];
        $request = <<<XML
<packet>
    <webspace>
        <get>
            <filter/>
            <dataset>
                <gen_info/>
            </dataset>
        </get>
    </webspace>
</packet>
XML;

        $response = pm_ApiRpc::getService()->call($request);

        if (isset($response->webspace->get->result)) {
            foreach ($response->webspace->get->result as $result) {
                if ((string) $result->status === 'ok' && isset($result->data->gen_info->name)) {
                    $domains[] = (string) $result->data->gen_info->name;
                }
            }
        }

        return $domains;
    }

    /**
     * Get DNS zone data for a domain from Plesk.
     *
     * @return array|null Zone data in Plesk format, or null if unavailable
     */
    private function getZoneData(string $domainName): ?array
    {
        $safeName = htmlspecialchars($domainName, ENT_XML1, 'UTF-8');
        $request = <<<XML
<packet>
    <dns>
        <get_rec>
            <filter>
                <site-name>{$safeName}</site-name>
            </filter>
        </get_rec>
    </dns>
</packet>
XML;

        try {
            $response = pm_ApiRpc::getService()->call($request);
        } catch (\Exception $e) {
            $logger = new Modules_Powerdns_Logger();
            $logger->err("Failed to get zone data for {$domainName}: " . $e->getMessage());
            return null;
        }

        if (!isset($response->dns->get_rec->result)) {
            return null;
        }

        $records = [];
        foreach ($response->dns->get_rec->result as $result) {
            if ((string) $result->status !== 'ok') {
                continue;
            }

            $data = $result->data;
            $records[] = [
                'host'  => (string) $data->host,
                'type'  => (string) $data->type,
                'value' => (string) $data->value,
                'ttl'   => isset($data->opt) ? (int) $data->opt : 3600,
            ];
        }

        if (empty($records)) {
            return null;
        }

        return [
            'name' => $domainName,
            'rr'   => $records,
        ];
    }
}
