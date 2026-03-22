# KloudStack Migration Plugin

WordPress plugin that enables secure site migration to the [KloudStack PaaS Platform](https://kloudstack.com.au).

## What it does

Install this plugin on your **existing (source) WordPress site** to allow KloudStack to securely export and migrate your database, media files, and configuration to a new KloudStack-hosted environment.

## Requirements

- WordPress 6.0+
- PHP 8.1+
- A KloudStack account with an active migration initiated via the KloudStack dashboard

## Installation

1. Download `kloudstack-migration.zip` from the [latest release](../../releases/latest)
2. In your WordPress admin go to **Plugins → Add New → Upload Plugin**
3. Upload the ZIP and click **Activate**
4. Go to **Settings → KloudStack Migration** and paste the plugin token provided in your KloudStack dashboard
5. Click **Save Settings** — a connection check runs automatically

## How it works

Once activated and configured with your token, the plugin exposes secure REST endpoints used by the KloudStack platform to:

- Validate connectivity and plugin configuration
- Export your database (streamed, not stored)
- Enumerate and upload media files
- Report source site metadata (WordPress version, PHP version, active plugins, database size)

All communication is authenticated via your one-time plugin token. The plugin does not store or transmit any data without an authenticated request from the KloudStack platform.

## Security

- Token-authenticated REST endpoints only
- No outbound connections initiated by the plugin
- No data stored outside your own WordPress database
- Proprietary licence — not for redistribution

## Releases

See [CHANGELOG.md](CHANGELOG.md) for version history.

## Planned

- **Auto-update notifications**: Bundle Plugin Update Checker library so WordPress admin shows "Update available" badge when a new GitHub Release is published. Admins will be able to click "Update Now" without manually re-installing the ZIP.

## Support

Contact [support@kloudstack.com.au](mailto:support@kloudstack.com.au) or open an issue in this repository.
