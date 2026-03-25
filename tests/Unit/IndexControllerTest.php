<?php

// Copyright 2024. All rights reserved.

declare(strict_types=1);

/**
 * Integration tests for IndexController.
 *
 * Uses a testable subclass that overrides Plesk RPC methods (getAllDomains,
 * getZoneData) and controller infrastructure (getRequest, _redirect, _status)
 * to test the controller logic without requiring a Plesk environment.
 */

use PHPUnit\Framework\TestCase;

class IndexControllerTest extends TestCase
{
    protected function setUp(): void
    {
        pm_Settings::reset();
        Modules_Powerdns_Logger::clearStoredErrors();
    }

    // ── init() ──────────────────────────────────────────

    public function testInitSetsPageTitle(): void
    {
        $controller = new TestableIndexController();
        $controller->init();

        $this->assertSame('PowerDNS', $controller->view->pageTitle);
    }

    // ── indexAction() ───────────────────────────────────

    public function testIndexActionGetRendersForm(): void
    {
        $controller = new TestableIndexController();
        $controller->setIsPost(false);
        $controller->init();
        $controller->indexAction();

        $this->assertInstanceOf(Modules_Powerdns_Form_Settings::class, $controller->view->form);
    }

    public function testIndexActionPostSavesSettings(): void
    {
        $controller = new TestableIndexController();
        $controller->setIsPost(true);
        $controller->setPostData([
            'apiUrl' => 'http://pdns:8081',
            'apiKey' => 'new-key',
            'serverId' => 'localhost',
            'ns1' => 'ns1.test.com',
            'zoneKind' => 'Native',
            'ipv6Prefix' => '48',
            'webhookUrl' => '',
            'enabled' => '',
        ]);
        $controller->init();
        $controller->indexAction();

        $this->assertContains('Settings saved successfully.', $controller->getInfoMessages());
    }

    // ── toolsAction() ───────────────────────────────────

    public function testToolsActionLoadsErrors(): void
    {
        $logger = new Modules_Powerdns_Logger();
        $logger->err('Test error');

        $controller = new TestableIndexController();
        $controller->init();
        $controller->toolsAction();

        $errors = $controller->view->errors;
        $this->assertCount(1, $errors);
        $this->assertSame('Test error', $errors[0]['message']);
    }

    // ── healthCheckAction() ─────────────────────────────

    public function testHealthCheckNonPostRedirects(): void
    {
        $controller = new TestableIndexController();
        $controller->setIsPost(false);
        $controller->init();
        $controller->healthCheckAction();

        $this->assertSame('/index/tools', $controller->getRedirectUrl());
    }

    public function testHealthCheckNoCredentialsShowsError(): void
    {
        $controller = new TestableIndexController();
        $controller->setIsPost(true);
        $controller->init();

        $controller->healthCheckAction();

        $this->assertContains('PowerDNS API credentials not configured.', $controller->getErrorMessages());
    }

    // ── syncPreviewAction() ─────────────────────────────

    public function testSyncPreviewNonPostRedirects(): void
    {
        $controller = new TestableIndexController();
        $controller->setIsPost(false);
        $controller->init();
        $controller->syncPreviewAction();

        $this->assertSame('/index/tools', $controller->getRedirectUrl());
    }

    public function testSyncPreviewNoCredentialsShowsError(): void
    {
        $controller = new TestableIndexController();
        $controller->setIsPost(true);
        $controller->init();

        $controller->syncPreviewAction();

        $this->assertContains('PowerDNS API credentials not configured.', $controller->getErrorMessages());
    }

    // ── syncAllAction() ─────────────────────────────────

    public function testSyncAllNonPostRedirects(): void
    {
        $controller = new TestableIndexController();
        $controller->setIsPost(false);
        $controller->init();
        $controller->syncAllAction();

        $this->assertSame('/index', $controller->getRedirectUrl());
    }

    public function testSyncAllNoCredentialsShowsError(): void
    {
        $controller = new TestableIndexController();
        $controller->setIsPost(true);
        $controller->init();

        $controller->syncAllAction();

        $this->assertContains('PowerDNS API credentials not configured.', $controller->getErrorMessages());
    }

    public function testSyncAllNoNameserversShowsError(): void
    {
        pm_Settings::set('apiUrl', 'http://pdns:8081');
        pm_Settings::set('apiKey', 'test-key');
        // No ns1/ns2

        $controller = new TestableIndexController();
        $controller->setIsPost(true);
        $controller->init();
        $controller->syncAllAction();

        $this->assertContains('Nameservers not configured.', $controller->getErrorMessages());
    }

    // ── clearErrorsAction() ─────────────────────────────

    public function testClearErrorsActionClearsLog(): void
    {
        $logger = new Modules_Powerdns_Logger();
        $logger->err('Error to clear');
        $this->assertNotEmpty(Modules_Powerdns_Logger::getStoredErrors());

        $controller = new TestableIndexController();
        $controller->setIsPost(true);
        $controller->init();
        $controller->clearErrorsAction();

        $this->assertEmpty(Modules_Powerdns_Logger::getStoredErrors());
        $this->assertContains('Error log cleared.', $controller->getInfoMessages());
    }

    public function testClearErrorsNonPostJustRedirects(): void
    {
        $logger = new Modules_Powerdns_Logger();
        $logger->err('Should remain');

        $controller = new TestableIndexController();
        $controller->setIsPost(false);
        $controller->init();
        $controller->clearErrorsAction();

        // Errors should NOT be cleared
        $this->assertNotEmpty(Modules_Powerdns_Logger::getStoredErrors());
        $this->assertSame('/index/tools', $controller->getRedirectUrl());
    }
}
