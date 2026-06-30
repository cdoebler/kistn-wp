<?php
/**
 * WP-CLI command: wp inventory push
 *
 * @package Kistn
 */

/**
 * WP-CLI command handler for the inventory push command.
 */
class Kistn_Cli_Command {

	/**
	 * Constructor.
	 *
	 * @param Kistn_Inventory_Pusher $pusher Inventory pusher instance.
	 */
	public function __construct( private readonly Kistn_Inventory_Pusher $pusher ) {}

	/**
	 * Push inventory to the Kistn API.
	 *
	 * ## EXAMPLES
	 *
	 *   wp inventory push
	 *
	 * @when after_wp_load
	 */
	public function push(): void {
		WP_CLI::log( 'Pushing inventory...' );
		$this->pusher->push();
		WP_CLI::success( 'Inventory pushed.' );
	}
}
