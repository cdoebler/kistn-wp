<?php
/**
 * Wires all dependencies and registers WP hooks.
 *
 * @package Kistn
 */

/**
 * Main plugin class.
 */
class Kistn_Plugin {

	/**
	 * Config instance.
	 *
	 * @var Kistn_Config
	 */
	private Kistn_Config $config;

	/**
	 * Inventory pusher instance.
	 *
	 * @var Kistn_Inventory_Pusher
	 */
	private Kistn_Inventory_Pusher $pusher;

	/**
	 * Constructor. Wires all dependencies.
	 */
	public function __construct() {
		$helper       = new Kistn_Config_Helper();
		$this->config = new Kistn_Config( $helper );
		$http         = new Kistn_Http_Client( $this->config );
		$wpscan       = new Kistn_Wpscan_Client( $this->config->wpscan_token() );
		$this->pusher = new Kistn_Inventory_Pusher(
			$http,
			new Kistn_Plugin_Collector(),
			new Kistn_Theme_Collector(),
			$wpscan
		);
	}

	/**
	 * Register hooks. Called on plugins_loaded.
	 */
	public function init(): void {
		add_action( 'admin_menu', array( new Kistn_Settings_Page( $this->config ), 'register' ) );

		add_action( 'kistn_run_inventory_push', array( $this, 'maybe_push' ) );

		if ( defined( 'WP_CLI' ) && true === WP_CLI ) {
			WP_CLI::add_command( 'kistn', new Kistn_Cli_Command( $this->pusher ) );
			return;
		}

		$mode = $this->config->schedule_mode();

		if ( 'admin-init' === $mode ) {
			add_action( 'admin_init', array( $this, 'maybe_push' ) );
		}

		if ( 'wp-cli' !== $mode && ! wp_next_scheduled( 'kistn_run_inventory_push' ) ) {
			wp_schedule_event( time(), $this->config->schedule_interval(), 'kistn_run_inventory_push' );
		}
	}

	/**
	 * Run inventory push if config is valid.
	 */
	public function maybe_push(): void {
		if ( ! $this->config->is_valid() ) {
			return;
		}

		// Debounce: admin-init fires on every admin page load. Skip if we ran recently
		// so editors are not forced through synchronous API round-trips on each request.
		if ( false !== get_transient( 'kistn_push_throttle' ) ) {
			return;
		}
		set_transient( 'kistn_push_throttle', time(), 10 * MINUTE_IN_SECONDS );

		// Only lift the execution-time limit off the request path (cron/cli), never while
		// serving an admin page request where a hung API call would stall wp-admin.
		if ( wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) ) {
			set_time_limit( 0 );
		}

		$this->pusher->push();
	}

	/**
	 * Plugin activation hook — schedules cron if mode is not wp-cli.
	 */
	public function activate(): void {
		if (
			'wp-cli' !== $this->config->schedule_mode()
			&& ! wp_next_scheduled( 'kistn_run_inventory_push' )
		) {
			wp_schedule_event( time(), $this->config->schedule_interval(), 'kistn_run_inventory_push' );
		}
	}

	/**
	 * Plugin deactivation hook — clears scheduled cron event.
	 */
	public function deactivate(): void {
		$timestamp = wp_next_scheduled( 'kistn_run_inventory_push' );

		if ( false !== $timestamp ) {
			wp_unschedule_event( $timestamp, 'kistn_run_inventory_push' );
		}
	}
}
