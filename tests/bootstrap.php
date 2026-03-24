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

if (!class_exists('pm_ApiRpc')) {
    class pm_ApiRpc
    {
        public static function getService(): pm_ApiRpc_Service
        {
            return new pm_ApiRpc_Service();
        }
    }

    class pm_ApiRpc_Service
    {
        /** @return \SimpleXMLElement */
        public function call(string $request): \SimpleXMLElement
        {
            return new \SimpleXMLElement('<packet/>');
        }
    }
}

if (!class_exists('pm_Controller_Action')) {
    class pm_Controller_Action
    {
        /** @var pm_View_Stub */
        public $view;

        /** @var pm_Status_Stub */
        public $_status;

        public function init(): void
        {
            $this->view = new pm_View_Stub();
            $this->_status = new pm_Status_Stub();
        }

        public function getRequest(): pm_Request_Stub
        {
            return new pm_Request_Stub();
        }

        public function _redirect(string $url): void {}
    }

    /**
     * @property string $pageTitle
     * @property mixed $form
     * @property mixed $enabled
     * @property mixed $errors
     */
    class pm_View_Stub
    {
        /** @var array<string, mixed> */
        private array $data = [];

        public function __set(string $name, mixed $value): void
        {
            $this->data[$name] = $value;
        }

        public function __get(string $name): mixed
        {
            return $this->data[$name] ?? null;
        }

        public function __isset(string $name): bool
        {
            return isset($this->data[$name]);
        }
    }

    class pm_Status_Stub
    {
        /** @var string[] */
        public array $infoMessages = [];
        /** @var string[] */
        public array $errorMessages = [];

        public function addInfo(string $message): void
        {
            $this->infoMessages[] = $message;
        }

        public function addError(string $message): void
        {
            $this->errorMessages[] = $message;
        }
    }

    class pm_Request_Stub
    {
        private bool $isPost = false;
        /** @var array<string, mixed> */
        private array $postData = [];

        public function setIsPost(bool $isPost): void
        {
            $this->isPost = $isPost;
        }

        /** @param array<string, mixed> $data */
        public function setPostData(array $data): void
        {
            $this->postData = $data;
        }

        public function isPost(): bool
        {
            return $this->isPost;
        }

        /**
         * @return mixed
         */
        public function getPost(?string $key = null): mixed
        {
            if ($key !== null) {
                return $this->postData[$key] ?? null;
            }
            return $this->postData;
        }
    }
}

require_once __DIR__ . '/../src/plib/library/Form/Settings.php';
require_once __DIR__ . '/../src/plib/controllers/IndexController.php';

/**
 * Testable subclass of IndexController.
 *
 * Overrides controller infrastructure to avoid Plesk SDK dependencies.
 */
class TestableIndexController extends IndexController
{
    private pm_Request_Stub $requestStub;
    private ?string $redirectUrl = null;

    public function __construct()
    {
        $this->requestStub = new pm_Request_Stub();
    }

    public function init(): void
    {
        $this->view = new pm_View_Stub();
        $this->_status = new pm_Status_Stub();
        $this->view->pageTitle = 'PowerDNS';
    }

    public function getRequest(): pm_Request_Stub
    {
        return $this->requestStub;
    }

    public function _redirect(string $url): void
    {
        $this->redirectUrl = $url;
    }

    public function setIsPost(bool $isPost): void
    {
        $this->requestStub->setIsPost($isPost);
    }

    /** @param array<string, mixed> $data */
    public function setPostData(array $data): void
    {
        $this->requestStub->setPostData($data);
    }

    public function getRedirectUrl(): ?string
    {
        return $this->redirectUrl;
    }

    /** @return string[] */
    public function getInfoMessages(): array
    {
        return $this->_status->infoMessages;
    }

    /** @return string[] */
    public function getErrorMessages(): array
    {
        return $this->_status->errorMessages;
    }
}
