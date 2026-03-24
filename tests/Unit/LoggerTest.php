<?php

// Copyright 2024. All rights reserved.

declare(strict_types=1);

/**
 * Unit tests for the Logger.
 */

use PHPUnit\Framework\TestCase;

class LoggerTest extends TestCase
{
    protected function setUp(): void
    {
        pm_Settings::reset();
    }

    public function testGetStoredErrorsReturnsEmptyWhenNone(): void
    {
        $this->assertSame([], Modules_Powerdns_Logger::getStoredErrors());
    }

    public function testErrPersistsToSettings(): void
    {
        $logger = new Modules_Powerdns_Logger();
        $logger->err('Test error message');

        $errors = Modules_Powerdns_Logger::getStoredErrors();
        $this->assertCount(1, $errors);
        $this->assertSame('Test error message', $errors[0]['message']);
    }

    public function testStoredErrorFormat(): void
    {
        $logger = new Modules_Powerdns_Logger();
        $logger->err('Formatted error');

        $errors = Modules_Powerdns_Logger::getStoredErrors();
        $this->assertArrayHasKey('timestamp', $errors[0]);
        $this->assertArrayHasKey('message', $errors[0]);
        $this->assertIsInt($errors[0]['timestamp']);
        $this->assertIsString($errors[0]['message']);
    }

    public function testClearStoredErrors(): void
    {
        $logger = new Modules_Powerdns_Logger();
        $logger->err('Error to clear');

        $this->assertNotEmpty(Modules_Powerdns_Logger::getStoredErrors());

        Modules_Powerdns_Logger::clearStoredErrors();
        $this->assertSame([], Modules_Powerdns_Logger::getStoredErrors());
    }

    public function testMaxStoredErrorsLimit(): void
    {
        $logger = new Modules_Powerdns_Logger();

        for ($i = 0; $i < 55; $i++) {
            $logger->err("Error #{$i}");
        }

        $errors = Modules_Powerdns_Logger::getStoredErrors();
        $this->assertCount(50, $errors);
        // Most recent error should be first
        $this->assertSame('Error #54', $errors[0]['message']);
    }

    public function testMultipleErrorsPreserveOrder(): void
    {
        $logger = new Modules_Powerdns_Logger();
        $logger->err('First error');
        $logger->err('Second error');

        $errors = Modules_Powerdns_Logger::getStoredErrors();
        $this->assertCount(2, $errors);
        // Most recent first (array_unshift)
        $this->assertSame('Second error', $errors[0]['message']);
        $this->assertSame('First error', $errors[1]['message']);
    }
}
