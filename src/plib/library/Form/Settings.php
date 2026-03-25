<?php
// Copyright 2024. All rights reserved.
// Note: strict_types intentionally omitted — Plesk's pm_Form_Simple
// passes values that may not satisfy strict type checking.

/**
 * Settings form for PowerDNS connection configuration.
 *
 * Displayed in the Plesk admin panel under Extensions > PowerDNS.
 * Validates credentials by attempting a test connection to the API.
 */
class Modules_Powerdns_Form_Settings extends pm_Form_Simple
{
    public function init(): void
    {
        parent::init();

        $this->addElement('text', 'apiUrl', [
            'label'       => 'PowerDNS API URL',
            'description' => 'Base URL of the PowerDNS API (e.g. http://pdns.example.com:8081)',
            'required'    => true,
            'value'       => pm_Settings::get('apiUrl', ''),
            'validators'  => [
                ['NotEmpty', true],
            ],
            'filters'     => [
                'StringTrim',
            ],
        ]);

        $hasKey = !empty(pm_Settings::get('apiKey', ''));
        $this->addElement('password', 'apiKey', [
            'label'       => 'API Key',
            'description' => $hasKey
                ? 'X-API-Key for PowerDNS authentication. Leave blank to keep the current key.'
                : 'X-API-Key for PowerDNS authentication',
            'required'    => !$hasKey,
            'renderPassword' => false,
        ]);

        $this->addElement('text', 'serverId', [
            'label'       => 'Server ID',
            'description' => 'PowerDNS server identifier (usually "localhost")',
            'required'    => true,
            'value'       => pm_Settings::get('serverId', 'localhost'),
        ]);

        $this->addElement('text', 'ns1', [
            'label'       => 'Primary Nameserver',
            'description' => 'e.g. ns1.example.com',
            'required'    => true,
            'value'       => pm_Settings::get('ns1', ''),
            'validators'  => [
                ['NotEmpty', true],
            ],
            'filters'     => [
                'StringTrim',
            ],
        ]);

        $this->addElement('text', 'ns2', [
            'label'       => 'Secondary Nameserver',
            'description' => 'e.g. ns2.example.com',
            'required'    => false,
            'value'       => pm_Settings::get('ns2', ''),
            'filters'     => [
                'StringTrim',
            ],
        ]);

        $this->addElement('select', 'zoneKind', [
            'label'        => 'Zone Mode',
            'description'  => 'Native: replication via shared database (recommended). Primary: sends NOTIFY to secondary DNS servers via AXFR.',
            'required'     => true,
            'value'        => pm_Settings::get('zoneKind', 'Native'),
            'multiOptions' => [
                'Native'  => 'Native (shared database, no NOTIFY)',
                'Primary' => 'Primary (NOTIFY + AXFR to secondaries)',
            ],
            'validators' => [
                ['InArray', true, ['haystack' => ['Native', 'Primary']]],
            ],
        ]);

        $this->addElement('select', 'ipv6Prefix', [
            'label'        => 'IPv6 Reverse Zone Prefix',
            'description'  => 'Prefix length (in bits) for IPv6 reverse DNS zone delegation. Must match your provider allocation.',
            'required'     => true,
            'value'        => pm_Settings::get('ipv6Prefix', '48'),
            'multiOptions' => [
                '32' => '/32 (8 nibbles — large allocation)',
                '48' => '/48 (12 nibbles — default ISP delegation)',
                '56' => '/56 (14 nibbles)',
                '64' => '/64 (16 nibbles — single subnet)',
            ],
            'validators' => [
                ['InArray', true, ['haystack' => ['32', '48', '56', '64']]],
            ],
        ]);

        $this->addElement('checkbox', 'dnssec', [
            'label'       => 'Enable DNSSEC by default',
            'description' => 'When checked, new zones are created with DNSSEC enabled. Most domains do not need this.',
            'value'       => pm_Settings::get('dnssec') ? '1' : '',
        ]);

        $this->addElement('text', 'webhookUrl', [
            'label'       => 'Notification Webhook URL',
            'description' => 'Optional: HTTPS URL to POST JSON notifications on sync failures. Leave blank to disable.',
            'required'    => false,
            'value'       => pm_Settings::get('webhookUrl', ''),
            'filters'     => [
                'StringTrim',
            ],
        ]);

        $this->addElement('checkbox', 'enabled', [
            'label'       => 'Enable PowerDNS integration',
            'description' => 'When enabled, all DNS zone changes in Plesk are synced to PowerDNS',
            'value'       => pm_Settings::get('enabled') ? '1' : '',
        ]);

        $this->addControlButtons([
            'sendTitle'  => 'Save',
            'cancelLink' => pm_Context::getModulesListUrl(),
        ]);
    }

    public function isValid($data): bool
    {
        if (!parent::isValid($data)) {
            return false;
        }

        // Validate webhook URL: must be HTTPS or empty (SSRF prevention)
        $webhookUrl = trim($data['webhookUrl'] ?? '');
        if ($webhookUrl !== '' && !str_starts_with($webhookUrl, 'https://')) {
            $this->getElement('webhookUrl')->addError(
                'Webhook URL must use HTTPS for security.'
            );
            return false;
        }

        // If not being enabled, skip connection test
        if (empty($data['enabled'])) {
            return true;
        }

        // Validate connection to PowerDNS API
        $apiUrl   = $data['apiUrl'] ?? '';
        $apiKey   = $data['apiKey'] ?? '';
        $serverId = $data['serverId'] ?? 'localhost';

        // If no new key submitted, use the stored one for validation
        if (empty($apiKey)) {
            $apiKey = pm_Settings::get('apiKey', '');
        }

        if (empty($apiUrl) || empty($apiKey)) {
            return true; // Other validators will catch this
        }

        try {
            $client = new Modules_Powerdns_Client($apiUrl, $apiKey, $serverId);
            $client->testConnection();
        } catch (Modules_Powerdns_Exception $e) {
            $this->getElement('apiUrl')->addError(
                'Cannot connect to PowerDNS: ' . $e->getMessage()
            );
            return false;
        } catch (\Exception $e) {
            $this->getElement('apiUrl')->addError(
                'Connection error: ' . $e->getMessage()
            );
            return false;
        }

        return true;
    }

    /**
     * Persist validated settings.
     */
    public function process(): void
    {
        $values = $this->getValues();

        pm_Settings::set('apiUrl', $values['apiUrl']);
        // Only update the API key if a new value was provided
        if (!empty($values['apiKey'])) {
            pm_Settings::set('apiKey', $values['apiKey']);
        }
        pm_Settings::set('serverId', $values['serverId']);
        pm_Settings::set('ns1', $values['ns1']);
        pm_Settings::set('ns2', $values['ns2'] ?? '');
        pm_Settings::set('zoneKind', $values['zoneKind'] ?? 'Native');
        pm_Settings::set('ipv6Prefix', $values['ipv6Prefix'] ?? '48');
        pm_Settings::set('webhookUrl', $values['webhookUrl'] ?? '');
        pm_Settings::set('dnssec', !empty($values['dnssec']) ? '1' : '');
        pm_Settings::set('enabled', !empty($values['enabled']) ? '1' : '');
    }
}
