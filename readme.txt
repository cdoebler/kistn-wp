=== Kistn API Client ===
Contributors: grissi
Tags: inventory, security, vulnerability, monitoring
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 1.0.0
License: MIT

Pushes installed plugin and theme inventory to the Kistn API for vulnerability monitoring.

== Description ==

Collects installed plugins, themes, and WordPress core, then pushes inventory to your Kistn server for centralized vulnerability monitoring.

**Push flow:**

1. Preflight — asks the server which slugs need a fresh advisory check and which are known-private.
2. Hash check — skips push if inventory unchanged.
3. WPScan lookup — queries the WPScan vulnerability database only for stale, non-private slugs.
4. Push — sends packages, vulnerability findings, advisory snapshots, and any newly-discovered private slugs.

Private packages (those absent from the WPScan database) are tracked server-side so subsequent runs never waste WPScan quota on them. When the server later confirms a package is public, the project owner is notified.

**Configuration** via Settings → Kistn, or via constants in wp-config.php:

  define( 'KISTN_BASE_URL', 'https://your-server.example.com' );
  define( 'KISTN_PROJECT_ID', 'your-project-uuid' );
  define( 'KISTN_TOKEN', 'your-api-token' );
  define( 'KISTN_WPSCAN_TOKEN', 'your-wpscan-api-token' ); // optional, enables vulnerability lookups

== External services ==

This plugin can connect to WPScan API to obtain latest security information about your installation. Use of this feature is optional. To use this feature, you need a WPScan account and your own API token.

When the feature is used, this plugin sends information about installed WordPress core, plugins and themes to retrieve latest security advisories about your installed components. The service is provided by "WPScan": https://wpscan.com/terms/, https://automattic.com/privacy/.

== Installation ==

1. Upload the plugin ZIP via Plugins → Add New → Upload Plugin.
2. Activate the plugin.
3. Go to Settings → Kistn and configure your API credentials.

== Changelog ==

= 1.0.0 =
* Initial release.
