<?php

declare(strict_types=1);

// Copyright 2024. All rights reserved.

/**
 * Simple logger that writes to STDERR and persists recent errors
 * via pm_Settings for the admin UI.
 *
 * Context behaviour:
 * - CLI scripts (powerdns.php): STDERR is captured by Plesk's custom DNS
 *   backend handler and appears in the Plesk panel logs.
 * - Web requests (IndexController): STDERR goes to the web server error log
 *   (e.g. Apache/nginx), not the Plesk extension log.  Errors are still
 *   persisted via pm_Settings and displayed in the admin Tools tab.
 */
class Modules_Powerdns_Logger
{
    private const MAX_STORED_ERRORS = 50;
    private const SETTINGS_KEY = 'powerdns_errors';

    public function info(string $message): void
    {
        $this->write('INFO', $message);
    }

    public function warn(string $message): void
    {
        $this->write('WARN', $message);
    }

    public function err(string $message): void
    {
        $this->write('ERROR', $message);
        self::persistError($message);
    }

    private function write(string $level, string $message): void
    {
        $ts = date('Y-m-d H:i:s');
        fwrite(STDERR, "[{$ts}] [{$level}] {$message}\n");
    }

    /**
     * Store recent errors in pm_Settings so the admin UI can display them.
     */
    private static function persistError(string $message): void
    {
        try {
            $errors = self::getStoredErrors();
            array_unshift($errors, [
                'timestamp' => time(),
                'message'   => $message,
            ]);
            $errors = array_slice($errors, 0, self::MAX_STORED_ERRORS);
            pm_Settings::set(self::SETTINGS_KEY, json_encode($errors) ?: '[]');
        } catch (\Exception $e) {
            // Settings storage unavailable — ignore silently
        }
    }

    /**
     * @return array<int, array{timestamp: int, message: string}>
     */
    public static function getStoredErrors(): array
    {
        try {
            $json = pm_Settings::get(self::SETTINGS_KEY, '[]') ?? '[]';
            return json_decode($json, true) ?: [];
        } catch (\Exception $e) {
            return [];
        }
    }

    public static function clearStoredErrors(): void
    {
        try {
            pm_Settings::set(self::SETTINGS_KEY, '[]');
        } catch (\Exception $e) {
            // ignore
        }
    }
}
