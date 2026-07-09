=== Kistn API Client ===
Contributors: grissi
Tags: inventory, security, vulnerability, monitoring
Requires at least: 6.0
Tested up to: 7.0
Requires PHP: 8.3
Stable tag: 1.0.1
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

1. In your WordPress admin, go to Plugins → Add New, search for "Kistn API Client", install and activate.
2. Alternatively, upload the plugin ZIP via Plugins → Add New → Upload Plugin, then activate.
3. Alternatively, via Composer: `composer require kistn/wp-client`, then activate as usual.
4. Go to Settings → Kistn and configure your API credentials.

== Changelog ==

= 1.0.2 =

= 1.0.1 =
* Fix: WPScan requests now pause until the next day (site timezone) after hitting the 429 rate limit, instead of retrying every remaining slug in the same run.
* Change: The Push Interval field is now hidden when Schedule Mode is set to WP-CLI only (it doesn't apply to that mode).
* Change: Renamed the WP-CLI command from `wp inventory push` to `wp kistn push`.
* Change: Added an example placeholder to the API Base URL field.
* Docs: Documented WordPress-plugin-repository installation as the primary install method.
* Change: The server-crontab hint under Settings → Kistn now toggles live when Schedule Mode is switched, instead of only appearing after a save.
* Docs: Documented Composer installation option (`composer require kistn/wp-client`) in README.md and readme.txt.

= 1.0.0 =
* Initial release.
