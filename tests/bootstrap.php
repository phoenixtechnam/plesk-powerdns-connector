<?php

declare(strict_types=1);

/**
 * Test bootstrap: defines Plesk SDK stubs and loads the Composer autoloader.
 *
 * All test files share these stubs — the static $store is reset between tests
 * via pm_Settings::reset() called in setUp().
 */

if (!class_exists('pm_Exception')) {
    class pm_Exception extends \RuntimeException {}
}

if (!class_exists('pm_Settings')) {
    class pm_Settings
    {
        /** @var array<string, mixed> */
        private static array $store = [];

        /**
         * @return string|null
         */
        public static function get(string $key, ?string $default = null): ?string
        {
            return self::$store[$key] ?? $default;
        }

        public static function set(string $key, string $value): void
        {
            self::$store[$key] = $value;
        }

        /**
         * Reset all settings — call in setUp() to prevent inter-test contamination.
         */
        public static function reset(): void
        {
            self::$store = [];
        }
    }
}

// Composer autoloader (loads Guzzle, PHPUnit, etc.)
require_once __DIR__ . '/../src/plib/vendor/autoload.php';

// Load library classes that tests need
require_once __DIR__ . '/../src/plib/library/Exception.php';
require_once __DIR__ . '/../src/plib/library/Logger.php';
require_once __DIR__ . '/../src/plib/library/DnsUtils.php';
require_once __DIR__ . '/../src/plib/library/ReverseDns.php';
require_once __DIR__ . '/../src/plib/library/ZoneFormatter.php';
require_once __DIR__ . '/../src/plib/library/Client.php';
require_once __DIR__ . '/../src/plib/library/CommandHandler.php';
