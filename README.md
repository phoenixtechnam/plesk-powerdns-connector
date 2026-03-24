# Plesk PowerDNS Connector

A Plesk extension that syncs DNS zones to external PowerDNS servers via the PowerDNS REST API.

## How It Works

This extension registers a **Custom DNS Backend** with Plesk. Every time a DNS zone is created, updated, or deleted in Plesk, the backend script translates the zone data and pushes it to your PowerDNS server via the HTTP API.

## Requirements

- Plesk Obsidian 18.0.40+
- PHP 7.4+
- PowerDNS Authoritative Server 4.x with the HTTP API enabled
- **No other DNS extension active** (Slave DNS Manager, Route53, DigitalOcean DNS must be disabled)

## PowerDNS Server Setup

Ensure your PowerDNS server has the HTTP API enabled in `pdns.conf`:

```ini
api=yes
api-key=your-secret-api-key
webserver=yes
webserver-address=0.0.0.0
webserver-port=8081
webserver-allow-from=YOUR_PLESK_SERVER_IP/32
```

Restart PowerDNS after changing the configuration.

## Installation

### From Source

```bash
# Clone the repository
git clone https://github.com/your-repo/plesk-powerdns-connector.git
cd plesk-powerdns-connector

# Install PHP dependencies
composer install --no-dev

# Package the extension
cd src
plesk bin extension --register powerdns

# Or create a distributable ZIP
plesk bin extension --pack powerdns
```

### From ZIP

```bash
plesk bin extension -i powerdns.zip
```

## Configuration

1. Log in to Plesk as administrator
2. Go to **Extensions** > **PowerDNS**
3. Enter your PowerDNS API URL (e.g., `http://pdns.example.com:8081`)
4. Enter the API key
5. Set the Server ID (usually `localhost`)
6. Configure your nameservers (ns1, ns2)
7. Check **Enable PowerDNS integration**
8. Click **Save**

The extension will validate the connection before saving.

## Bulk Sync

To sync all existing Plesk domains to PowerDNS:

1. Go to **Extensions** > **PowerDNS** > **Tools** tab
2. Click **Sync All Domains**

This is useful for initial migration or recovery after connectivity issues.

## Supported Record Types

A, AAAA, CNAME, MX, NS, TXT, SRV, CAA, PTR, ALIAS, TLSA, SSHFP, DS, NAPTR, LOC, HINFO, RP

## Uninstallation

```bash
plesk bin extension -r powerdns
```

This unregisters the custom DNS backend from Plesk. **Zones on PowerDNS are preserved** (not deleted).

## Development

### Running Tests

```bash
composer install
./vendor/bin/phpunit
```

### Project Structure

```
src/
├── meta.xml                    # Plesk extension metadata
├── plib/
│   ├── scripts/
│   │   ├── powerdns.php        # Core: Custom DNS backend script
│   │   ├── post-install.php    # Registers backend with Plesk
│   │   └── pre-uninstall.php   # Unregisters backend
│   ├── controllers/
│   │   └── IndexController.php # Admin UI controller
│   ├── library/
│   │   ├── Client.php          # PowerDNS API client
│   │   ├── ZoneFormatter.php   # Plesk → PDNS format translator
│   │   ├── Exception.php       # Custom exception
│   │   ├── Logger.php          # Logging utility
│   │   └── Form/
│   │       └── Settings.php    # Connection settings form
│   └── views/                  # Admin UI templates
└── htdocs/
    └── index.php               # Extension entry point
```

## License

Apache-2.0
