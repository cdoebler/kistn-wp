<?php
/**
 * Uninstall cleanup. Removes all Kistn options, transients, and scheduled events.
 *
 * @package Kistn
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

wp_clear_scheduled_hook( 'kistn_run_inventory_push' );

$kistn_options = array(
	'kistn_base_url',
	'kistn_project_id',
	'kistn_token',
	'kistn_wpscan_token',
	'kistn_schedule_mode',
	'kistn_schedule_interval',
	'kistn_last_error',
);

foreach ( $kistn_options as $kistn_option ) {
	delete_option( $kistn_option );
}

delete_transient( 'kistn_push_throttle' );

// WPScan results are cached under dynamic per-slug keys (kistn_wpscan_<eco>_<slug>);
// remove them and their timeout siblings directly.
global $wpdb;
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-time uninstall cleanup of dynamic transient keys.
$wpdb->query(
	"DELETE FROM {$wpdb->options}
	 WHERE option_name LIKE '\_transient\_kistn\_wpscan\_%'
	    OR option_name LIKE '\_transient\_timeout\_kistn\_wpscan\_%'"
);
