# kistn/wp

WordPress plugin client for the Kistn API. Collects installed plugins, themes, and WordPress core, then pushes inventory for centralized vulnerability monitoring.

## Requirements

- WordPress 6.0+
- PHP 8.3+
- WPScan API token (optional — enables vulnerability lookups)

## Installation

Upload the plugin ZIP via **Plugins → Add New → Upload Plugin**, then activate.

## Configuration

**Settings page:** Settings → Kistn

**Or constants in `wp-config.php`:**

```php
define( 'KISTN_BASE_URL',      'https://your-server.example.com' );
define( 'KISTN_PROJECT_ID',    'your-project-uuid' );
define( 'KISTN_TOKEN',         'your-api-token' );
define( 'KISTN_WPSCAN_TOKEN',  'your-wpscan-api-token' ); // optional
```

Constants take precedence over settings-page values.

## Push Flow

Each run (scheduled or CLI):

1. **Preflight** — `POST /preflight/wp` — server returns stale slugs, cached advisory payloads, and known-private slugs.
2. **Hash check** — `GET /hashes` for all ecosystems; compare each locally; skip push if all unchanged.
3. **WPScan lookup** — query WPScan only for stale, non-private slugs. Slugs returning 404 are reported as `private_packages`.
4. **Push** — `POST /inventory` bundled: `{"ecosystems": {"wp-plugin": {...}, "wp-theme": {...}, "wp-core": {...}}}` — only ecosystems that changed.

## CLI

```bash
wp kistn push
```

## What gets collected

| Collector | Source |
|---|---|
| `Kistn_Plugin_Collector` | Active and inactive plugins |
| `Kistn_Theme_Collector` | Active theme + parent (child themes flagged `is_child=true`) |
| `Kistn_Core_Collector` | WordPress core version |

Packages without a `source_url` (no WordPress.org listing) are inferred as private without querying WPScan.
