<?php

// Copyright 2024. All rights reserved.

declare(strict_types=1);

/**
 * Unit tests for the DnsUtils utility class.
 */

use PHPUnit\Framework\TestCase;

class DnsUtilsTest extends TestCase
{
    public function testEnsureTrailingDotAdds(): void
    {
        $this->assertSame('example.com.', Modules_Powerdns_DnsUtils::ensureTrailingDot('example.com'));
    }

    public function testEnsureTrailingDotPreservesExisting(): void
    {
        $this->assertSame('example.com.', Modules_Powerdns_DnsUtils::ensureTrailingDot('example.com.'));
    }

    public function testEnsureTrailingDotEmptyString(): void
    {
        $this->assertSame('', Modules_Powerdns_DnsUtils::ensureTrailingDot(''));
    }

    public function testEnsureTrailingDotRootDot(): void
    {
        $this->assertSame('.', Modules_Powerdns_DnsUtils::ensureTrailingDot('.'));
    }

    public function testEnsureTrailingDotMultipleDots(): void
    {
        $this->assertSame('example.com.', Modules_Powerdns_DnsUtils::ensureTrailingDot('example.com...'));
    }
}
