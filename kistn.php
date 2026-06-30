<?php
/**
 * Plugin Name:       Kistn API Client
 * Description:       Pushes installed plugin and theme inventory to the Kistn API for vulnerability monitoring.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      8.3
 * Author:            Christian Doebler
 * Author URI:        https://christian-doebler.net
 * License:           MIT
 * Text Domain:       kistn
 *
 * @package Kistn
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/src/interface-collector.php';
require_once __DIR__ . '/src/class-config-helper.php';
require_once __DIR__ . '/src/class-config.php';
require_once __DIR__ . '/src/class-http-client.php';
require_once __DIR__ . '/src/class-plugin-collector.php';
require_once __DIR__ . '/src/class-theme-collector.php';
require_once __DIR__ . '/src/class-core-collector.php';
require_once __DIR__ . '/src/class-wpscan-client.php';
require_once __DIR__ . '/src/class-inventory-pusher.php';
require_once __DIR__ . '/src/class-settings-page.php';
require_once __DIR__ . '/src/class-cli-command.php';
require_once __DIR__ . '/src/class-plugin.php';

// phpcs:disable WordPress.Variables.GlobalVariables.OverrideProhibited
$kistn_plugin = new Kistn_Plugin();
// phpcs:enable WordPress.Variables.GlobalVariables.OverrideProhibited

register_activation_hook( __FILE__, array( $kistn_plugin, 'activate' ) );
register_deactivation_hook( __FILE__, array( $kistn_plugin, 'deactivate' ) );

add_action( 'plugins_loaded', array( $kistn_plugin, 'init' ) );
