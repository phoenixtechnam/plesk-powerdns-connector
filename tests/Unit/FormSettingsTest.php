<?php

// Copyright 2024. All rights reserved.

declare(strict_types=1);

/**
 * Unit tests for Form/Settings.
 */

use PHPUnit\Framework\TestCase;

class FormSettingsTest extends TestCase
{
    protected function setUp(): void
    {
        pm_Settings::reset();
    }

    public function testInitCreatesAllElements(): void
    {
        $form = new Modules_Powerdns_Form_Settings();
        $form->init();

        // getValues should contain default values for all fields
        $values = $form->getValues();
        $this->assertArrayHasKey('apiUrl', $values);
        $this->assertArrayHasKey('serverId', $values);
        $this->assertArrayHasKey('ns1', $values);
        $this->assertArrayHasKey('ns2', $values);
        $this->assertArrayHasKey('zoneKind', $values);
        $this->assertArrayHasKey('ipv6Prefix', $values);
        $this->assertArrayHasKey('webhookUrl', $values);
    }

    public function testInitLoadsValuesFromSettings(): void
    {
        pm_Settings::set('apiUrl', 'http://pdns:8081');
        pm_Settings::set('serverId', 'myserver');
        pm_Settings::set('ns1', 'ns1.test.com');
        pm_Settings::set('ipv6Prefix', '64');

        $form = new Modules_Powerdns_Form_Settings();
        $form->init();

        $values = $form->getValues();
        $this->assertSame('http://pdns:8081', $values['apiUrl']);
        $this->assertSame('myserver', $values['serverId']);
        $this->assertSame('ns1.test.com', $values['ns1']);
        $this->assertSame('64', $values['ipv6Prefix']);
    }

    public function testProcessPersistsSettings(): void
    {
        $form = new Modules_Powerdns_Form_Settings();
        $form->init();

        $form->isValid([
            'apiUrl'     => 'http://pdns:8081',
            'apiKey'     => 'secret-key',
            'serverId'   => 'localhost',
            'ns1'        => 'ns1.example.com',
            'ns2'        => 'ns2.example.com',
            'zoneKind'   => 'Primary',
            'ipv6Prefix' => '64',
            'webhookUrl' => 'https://hooks.example.com/dns',
            'dnssec'     => '1',
            'enabled'    => '1',
        ]);

        $form->process();

        $this->assertSame('http://pdns:8081', pm_Settings::get('apiUrl'));
        $this->assertSame('secret-key', pm_Settings::get('apiKey'));
        $this->assertSame('localhost', pm_Settings::get('serverId'));
        $this->assertSame('ns1.example.com', pm_Settings::get('ns1'));
        $this->assertSame('ns2.example.com', pm_Settings::get('ns2'));
        $this->assertSame('Primary', pm_Settings::get('zoneKind'));
        $this->assertSame('64', pm_Settings::get('ipv6Prefix'));
        $this->assertSame('https://hooks.example.com/dns', pm_Settings::get('webhookUrl'));
        $this->assertSame('1', pm_Settings::get('dnssec'));
        $this->assertSame('1', pm_Settings::get('enabled'));
    }

    public function testProcessSkipsEmptyApiKey(): void
    {
        pm_Settings::set('apiKey', 'existing-key');

        $form = new Modules_Powerdns_Form_Settings();
        $form->init();

        $form->isValid([
            'apiUrl'     => 'http://pdns:8081',
            'apiKey'     => '',
            'serverId'   => 'localhost',
            'ns1'        => 'ns1.example.com',
            'zoneKind'   => 'Native',
            'ipv6Prefix' => '48',
            'webhookUrl' => '',
            'dnssec'     => '',
            'enabled'    => '1',
        ]);

        $form->process();

        // API key should remain unchanged
        $this->assertSame('existing-key', pm_Settings::get('apiKey'));
    }

    public function testProcessDefaultsForMissingOptionalFields(): void
    {
        $form = new Modules_Powerdns_Form_Settings();
        $form->init();

        // Only required fields
        $form->isValid([
            'apiUrl'     => 'http://pdns:8081',
            'apiKey'     => 'key',
            'serverId'   => 'localhost',
            'ns1'        => 'ns1.example.com',
            'enabled'    => '',
        ]);

        $form->process();

        // Optional fields should use defaults
        $this->assertSame('', pm_Settings::get('ns2'));
        $this->assertSame('Native', pm_Settings::get('zoneKind'));
        $this->assertSame('48', pm_Settings::get('ipv6Prefix'));
        $this->assertSame('', pm_Settings::get('webhookUrl'));
        $this->assertSame('', pm_Settings::get('dnssec'));
    }
}
