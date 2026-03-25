<?php

declare(strict_types=1);

// Copyright 2024. All rights reserved.

/**
 * Translates Plesk's DNS zone JSON representation into
 * PowerDNS rrset format for the API.
 *
 * Plesk sends zone data as:
 *   { "name": "example.com.", "soa": {...}, "rr": [ { "host", "type", "value", "ttl" }, ... ] }
 *
 * PowerDNS expects:
 *   { "rrsets": [ { "name", "type", "changetype", "ttl", "records": [{ "content", "disabled" }] } ] }
 */
class Modules_Powerdns_ZoneFormatter
{
    /** Record types that PowerDNS supports and we should sync */
    private const SUPPORTED_TYPES = [
        'A', 'AAAA', 'CNAME', 'MX', 'NS', 'TXT', 'SRV',
        'CAA', 'PTR', 'ALIAS', 'TLSA', 'SSHFP', 'DS',
        'NAPTR', 'LOC', 'HINFO', 'RP',
    ];

    /** @var Modules_Powerdns_Logger */
    private $logger;

    /** @var string|null Primary nameserver FQDN for SOA records */
    private $primaryNs;

    /**
     * @param string|null $primaryNs Primary nameserver for SOA records.
     *                               If null, falls back to the first NS record
     *                               found in the zone data.
     */
    public function __construct(?string $primaryNs = null, ?Modules_Powerdns_Logger $logger = null)
    {
        $this->logger = $logger ?? new Modules_Powerdns_Logger();
        $this->primaryNs = $primaryNs;
    }

    /**
     * Convert Plesk zone data to PowerDNS rrsets (REPLACE mode).
     *
     * @param array $zoneData Plesk zone payload (keys: name, soa, rr)
     * @return array          Array of rrset objects ready for PDNS API
     */
    public function pleskToRrsets(array $zoneData): array
    {
        $rrsets = [];

        // Determine primary NS: explicit config > first NS in rr > fallback
        $primaryNs = $this->resolvePrimaryNs($zoneData);

        // Build SOA rrset from the soa object
        if (isset($zoneData['soa'])) {
            $rrsets[] = $this->buildSoaRrset($zoneData['name'], $zoneData['soa'], $primaryNs);
        }

        // Group rr entries by (name, type) to form rrsets
        $zoneName = Modules_Powerdns_DnsUtils::ensureTrailingDot($zoneData['name'] ?? '');
        if (isset($zoneData['rr']) && is_array($zoneData['rr'])) {
            $grouped = $this->groupRecords($zoneData['rr'], $zoneName);
            foreach ($grouped as $key => $records) {
                [$name, $type] = explode('|', $key, 2);
                $rrsets[] = $this->buildRrset($name, $type, $records);
            }
        }

        return $rrsets;
    }

    /**
     * Group Plesk rr records by (host, type).
     *
     * @param  array $records Plesk rr array
     * @return array<string, array> Keyed by "host|type"
     */
    private function groupRecords(array $records, string $zoneName = ''): array
    {
        $grouped = [];

        foreach ($records as $rr) {
            $type = strtoupper($rr['type'] ?? '');

            if (!in_array($type, self::SUPPORTED_TYPES, true)) {
                $this->logger->warn("Skipping unsupported record type: {$type}");
                continue;
            }

            $host = Modules_Powerdns_DnsUtils::ensureTrailingDot($rr['host'] ?? '');

            // Skip records that don't belong to this zone (e.g., stale
            // cross-zone records from domain aliases or copied zones).
            // PowerDNS rejects these with "Name is out of zone".
            if ($zoneName !== '' && !str_ends_with($host, $zoneName)) {
                $this->logger->warn("Skipping out-of-zone record: {$host} (zone: {$zoneName})");
                continue;
            }

            $key = "{$host}|{$type}";

            $grouped[$key][] = $rr;
        }

        return $grouped;
    }

    /**
     * Build a single PowerDNS rrset from grouped Plesk records.
     */
    private function buildRrset(string $name, string $type, array $records): array
    {
        $pdnsRecords = [];
        $ttl = 3600; // default

        foreach ($records as $rr) {
            $content = $this->formatContent($type, $rr['value'] ?? '', $rr);
            $ttl = (int) ($rr['ttl'] ?? $ttl);

            $pdnsRecords[] = [
                'content'  => $content,
                'disabled' => false,
            ];
        }

        return [
            'name'       => $name,
            'type'       => $type,
            'changetype' => 'REPLACE',
            'ttl'        => $ttl,
            'records'    => $pdnsRecords,
        ];
    }

    /**
     * Determine the primary nameserver for SOA records.
     *
     * Priority:
     *   1. Explicitly configured primary NS (from extension settings)
     *   2. First NS record found in the zone's rr data
     *   3. Fallback to "ns1.<zoneName>"
     */
    private function resolvePrimaryNs(array $zoneData): string
    {
        // 1. Explicit config
        if ($this->primaryNs !== null && $this->primaryNs !== '') {
            return Modules_Powerdns_DnsUtils::ensureTrailingDot($this->primaryNs);
        }

        // 2. First NS record in zone data
        if (isset($zoneData['rr']) && is_array($zoneData['rr'])) {
            foreach ($zoneData['rr'] as $rr) {
                if (strtoupper($rr['type'] ?? '') === 'NS' && !empty($rr['value'])) {
                    return Modules_Powerdns_DnsUtils::ensureTrailingDot($rr['value']);
                }
            }
        }

        // 3. Fallback
        $zoneName = Modules_Powerdns_DnsUtils::ensureTrailingDot($zoneData['name'] ?? '');
        return "ns1.{$zoneName}";
    }

    /**
     * Build the SOA rrset from Plesk's soa object.
     *
     * @param string $zoneName  Canonical zone name
     * @param array  $soa       Plesk SOA data (email, ttl, serial, refresh, retry, expire, minimum)
     * @param string $primaryNs Resolved primary nameserver FQDN
     */
    private function buildSoaRrset(string $zoneName, array $soa, string $primaryNs): array
    {
        $zoneName = Modules_Powerdns_DnsUtils::ensureTrailingDot($zoneName);

        // Plesk SOA fields: email, ttl, serial, refresh, retry, expire, minimum
        // Guard against both null and empty string — Plesk may send either
        $email = !empty($soa['email']) ? $soa['email'] : "hostmaster.{$zoneName}";
        // Convert email@domain to email.domain format for SOA
        $email = str_replace('@', '.', $email);
        $email = Modules_Powerdns_DnsUtils::ensureTrailingDot($email);

        $serial  = !empty($soa['serial']) ? $soa['serial'] : date('Ymd') . '01';
        $refresh = $soa['refresh'] ?? 10800;
        $retry   = $soa['retry']   ?? 3600;
        $expire  = $soa['expire']  ?? 604800;
        $minimum = $soa['minimum'] ?? 3600;
        $ttl     = $soa['ttl']     ?? 86400;

        $content = "{$primaryNs} {$email} {$serial} {$refresh} {$retry} {$expire} {$minimum}";

        return [
            'name'       => $zoneName,
            'type'       => 'SOA',
            'changetype' => 'REPLACE',
            'ttl'        => (int) $ttl,
            'records'    => [
                [
                    'content'  => $content,
                    'disabled' => false,
                ],
            ],
        ];
    }

    /**
     * Format record content for PowerDNS based on record type.
     *
     * Handles type-specific formatting requirements:
     * - TXT: ensure proper quoting
     * - MX/SRV: priority is part of the content string (Plesk already includes it)
     * - CNAME/NS/PTR/MX target: ensure trailing dot
     */
    /**
     * @param array<string, mixed> $rr Full Plesk record (has 'value', 'opt', etc.)
     */
    private function formatContent(string $type, string $value, array $rr = []): string
    {
        switch ($type) {
            case 'TXT':
                return $this->formatTxtContent($value);

            case 'ALIAS':
            case 'CNAME':
            case 'NS':
            case 'PTR':
                return Modules_Powerdns_DnsUtils::ensureTrailingDot($value);

            case 'MX':
                return $this->formatMxContent($value, $rr);

            case 'SRV':
                return $this->formatSrvContent($value, $rr);

            case 'CAA':
                // Plesk format: '0 issue "letsencrypt.org"'
                return $value;

            default:
                return $value;
        }
    }

    /**
     * Ensure TXT content is properly quoted for PowerDNS.
     */
    private function formatTxtContent(string $value): string
    {
        // If already properly quoted (balanced, non-empty content), return as-is.
        // Handles single-string ("...") and multi-string ("..." "...") TXT values.
        if (strlen($value) >= 2 && $value[0] === '"' && $value[-1] === '"') {
            return $value;
        }

        // Strip any unbalanced leading/trailing quotes before re-quoting
        $stripped = trim($value, '"');
        return '"' . str_replace('"', '\\"', $stripped) . '"';
    }

    /**
     * Format MX record: ensure target hostname has trailing dot.
     *
     * Plesk may send MX data in two formats:
     *   1. Combined: value = "10 mail.example.com"
     *   2. Split:    value = "mail.example.com", opt = "10"
     *
     * @param array<string, mixed> $rr Full Plesk record
     */
    private function formatMxContent(string $value, array $rr = []): string
    {
        // Try combined format first: "priority target"
        $parts = preg_split('/\s+/', trim($value), 2) ?: [];
        if (count($parts) === 2 && is_numeric($parts[0])) {
            return $parts[0] . ' ' . Modules_Powerdns_DnsUtils::ensureTrailingDot($parts[1]);
        }

        // Split format: priority in 'opt', target in 'value'
        $priority = $rr['opt'] ?? '10';
        return $priority . ' ' . Modules_Powerdns_DnsUtils::ensureTrailingDot($value);
    }

    /**
     * Format SRV record: ensure target hostname has trailing dot.
     *
     * Plesk may send SRV data in two formats:
     *   1. Combined: value = "10 5 5060 sip.example.com"
     *   2. Split:    value = "sip.example.com", opt = "10 5 5060"
     *              or value = "sip.example.com" with no priority/weight/port
     *
     * PowerDNS expects: "priority weight port target"
     *
     * @param array<string, mixed> $rr Full Plesk record
     */
    private function formatSrvContent(string $value, array $rr = []): string
    {
        // Try combined format: "priority weight port target"
        $parts = preg_split('/\s+/', trim($value), 4) ?: [];
        if (count($parts) === 4 && is_numeric($parts[0])) {
            $parts[3] = Modules_Powerdns_DnsUtils::ensureTrailingDot($parts[3]);
            return implode(' ', $parts);
        }

        // Split format: priority/weight/port in 'opt', target in 'value'
        $opt = trim((string) ($rr['opt'] ?? ''));
        $target = Modules_Powerdns_DnsUtils::ensureTrailingDot($value);

        if ($opt !== '') {
            // opt may contain "priority weight port" or just "priority"
            $optParts = preg_split('/\s+/', $opt) ?: [];
            if (count($optParts) === 3) {
                return "{$optParts[0]} {$optParts[1]} {$optParts[2]} {$target}";
            }
            if (count($optParts) === 1) {
                // Only priority — default weight=0, port=0
                return "{$optParts[0]} 0 0 {$target}";
            }
        }

        // Fallback: default priority/weight/port
        return "0 0 0 {$target}";
    }
}
