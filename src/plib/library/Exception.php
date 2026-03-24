<?php

declare(strict_types=1);

// Copyright 2024. All rights reserved.

/**
 * Custom exception for PowerDNS API errors.
 *
 * Wraps HTTP status codes and PowerDNS error responses
 * so callers can distinguish API failures from other errors.
 */
class Modules_Powerdns_Exception extends pm_Exception
{
    /** @var int|null */
    private $httpCode;

    /** @var string|null */
    private $pdnsError;

    /**
     * @param string         $message   Human-readable description
     * @param int|null       $httpCode  HTTP status from PowerDNS API
     * @param string|null    $pdnsError Raw error string from PDNS response
     * @param \Throwable|null $previous  Previous exception for chaining
     */
    public function __construct(
        string $message,
        ?int $httpCode = null,
        ?string $pdnsError = null,
        ?\Throwable $previous = null
    ) {
        $this->httpCode = $httpCode;
        $this->pdnsError = $pdnsError;
        parent::__construct($message, $httpCode ?? 0, $previous);
    }

    public function getHttpCode(): ?int
    {
        return $this->httpCode;
    }

    public function getPdnsError(): ?string
    {
        return $this->pdnsError;
    }
}
