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
require_once __DIR__ . '/../src/plib/library/NotificationService.php';

// Plesk form/controller stubs for testing Form/Settings and IndexController
if (!class_exists('pm_Form_Simple')) {
    class pm_Form_Simple
    {
        /** @var array<string, array<string, mixed>> */
        protected array $elements = [];
        /** @var array<string, mixed> */
        protected array $values = [];

        public function init(): void {}

        /** @param array<string, mixed> $options */
        public function addElement(string $type, string $name, array $options = []): void
        {
            $this->elements[$name] = $options;
            if (isset($options['value'])) {
                $this->values[$name] = $options['value'];
            }
        }

        /** @param array<string, mixed> $options */
        public function addControlButtons(array $options = []): void {}

        /** @param array<string, mixed> $data */
        public function isValid(array $data): bool
        {
            $this->values = array_merge($this->values, $data);
            return true;
        }

        /** @return array<string, mixed> */
        public function getValues(): array
        {
            return $this->values;
        }

        public function getElement(string $name): pm_Form_Element
        {
            return new pm_Form_Element();
        }
    }

    class pm_Form_Element
    {
        /** @var string[] */
        public array $errors = [];

        public function addError(string $message): void
        {
            $this->errors[] = $message;
        }
    }
}

if (!class_exists('pm_Context')) {
    class pm_Context
    {
        public static function init(string $name): void {}
        public static function getModulesListUrl(): string
        {
            return '/modules';
        }
    }
}

if (!class_exists('pm_Session')) {
    class pm_Session
    {
        public static function getClient(): pm_Client
        {
            return new pm_Client();
        }
        public static function getToken(): string
        {
            return 'test-csrf-token';
        }
    }

    class pm_Client
    {
        public function isAdmin(): bool
        {
            return true;
        }
    }
}

require_once __DIR__ . '/../src/plib/library/Form/Settings.php';
