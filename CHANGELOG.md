# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/).

## [1.0.0] - 2024-01-01

### Added
- Initial release of the Plesk PowerDNS Connector extension.
- Custom DNS backend integration with Plesk for automatic zone synchronization.
- PowerDNS Authoritative Server API client with retry logic for transient errors.
- Zone create, update, and delete operations.
- PTR record management for both IPv4 and IPv6 reverse DNS.
- Configurable IPv6 reverse zone prefix length (32, 48, 56, 64 bits).
- DNSSEC support (enable/disable per zone, cryptokey management).
- Admin UI with settings form and connection validation.
- Bulk sync tool for migrating all existing domains to PowerDNS.
- Error log viewer in the admin Tools tab.
- Support for 17 DNS record types: A, AAAA, CNAME, MX, NS, TXT, SRV, CAA, PTR, ALIAS, TLSA, SSHFP, DS, NAPTR, LOC, HINFO, RP.
- SOA record generation with configurable primary nameserver.
- Native and Primary zone mode support.
- PHPStan static analysis at level 6.
- PHP-CS-Fixer code style enforcement.
- GitHub Actions CI pipeline (PHP 8.1–8.4).
- Comprehensive test suite (58+ tests).
