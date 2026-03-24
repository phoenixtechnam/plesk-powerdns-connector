<?php

declare(strict_types=1);

// Copyright 2024. All rights reserved.

/**
 * Shared DNS utility functions.
 */
class Modules_Powerdns_DnsUtils
{
    /**
     * Ensure a DNS name has a trailing dot (FQDN format).
     */
    public static function ensureTrailingDot(string $name): string
    {
        if ($name === '' || $name === '.') {
            return $name;
        }
        return rtrim($name, '.') . '.';
    }
}
