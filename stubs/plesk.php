<?php

/**
 * Plesk SDK stubs for PHPStan static analysis.
 *
 * These stubs provide type information for Plesk SDK classes that are
 * not available outside the Plesk environment.
 */

class pm_Exception extends \RuntimeException
{
}

class pm_Settings
{
    /** @return string|null */
    public static function get(string $key, ?string $default = null): ?string
    {
    }

    public static function set(string $key, string $value): void
    {
    }
}

class pm_Loader
{
    public static function registerAutoload(): void
    {
    }
}

class pm_Context
{
    public static function init(string $name): void
    {
    }

    public static function getModulesListUrl(): string
    {
    }
}

class pm_Session
{
    public static function getClient(): pm_Client
    {
    }

    public static function getToken(): string
    {
    }
}

class pm_Client
{
    public function isAdmin(): bool
    {
    }
}

class pm_ApiCli
{
    /**
     * @param string $command
     * @param string[] $args
     */
    public static function call(string $command, array $args = []): void
    {
    }

    /**
     * @param string $command
     * @param string[] $args
     * @return string
     */
    public static function callSilent(string $command, array $args = []): string
    {
    }
}

class pm_ApiRpc
{
    public static function getService(): pm_ApiRpc_Service
    {
    }
}

class pm_ApiRpc_Service
{
    /** @return \SimpleXMLElement */
    public function call(string $request): \SimpleXMLElement
    {
    }
}

class pm_Form_Simple
{
    public function init(): void
    {
    }

    /**
     * @param string $type
     * @param string $name
     * @param array<string, mixed> $options
     */
    public function addElement(string $type, string $name, array $options = []): void
    {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function addControlButtons(array $options = []): void
    {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function isValid(array $data): bool
    {
    }

    /** @return array<string, mixed> */
    public function getValues(): array
    {
    }

    public function getElement(string $name): pm_Form_Element
    {
    }
}

class pm_Form_Element
{
    public function addError(string $message): void
    {
    }
}

class pm_Controller_Action
{
    /** @var pm_View */
    public $view;

    /** @var pm_Status */
    public $_status;

    public function init(): void
    {
    }

    public function getRequest(): pm_Request
    {
    }

    public function _redirect(string $url): void
    {
    }
}

/**
 * @property string $pageTitle
 * @property mixed $form
 * @property mixed $enabled
 * @property mixed $errors
 */
class pm_View
{
    /** @var string */
    public $pageTitle = '';

    public function __set(string $name, mixed $value): void
    {
    }

    public function __get(string $name): mixed
    {
    }
}

class pm_Status
{
    public function addInfo(string $message): void
    {
    }

    public function addError(string $message): void
    {
    }
}

class pm_Request
{
    public function isPost(): bool
    {
    }

    /**
     * @return mixed
     */
    public function getPost(?string $key = null): mixed
    {
    }
}
