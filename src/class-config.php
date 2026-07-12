<?php
/**
 * Resolves plugin configuration from PHP constants (priority) or wp_options.
 *
 * @package Kistn
 */

/**
 * Resolves plugin configuration from PHP constants (priority) or wp_options.
 */
class Kistn_Config {

	private const VALID_SCHEDULE_MODES = array( 'wp-cron', 'admin-init', 'wp-cli' );
	private const VALID_INTERVALS      = array( 'hourly', 'twicedaily', 'daily' );
	private const CONSTANT_MAP         = array(
		'base_url'          => 'KISTN_BASE_URL',
		'project_id'        => 'KISTN_PROJECT_ID',
		'token'             => 'KISTN_TOKEN',
		'wpscan_token'      => 'KISTN_WPSCAN_TOKEN',
		'schedule_mode'     => 'KISTN_SCHEDULE_MODE',
		'schedule_interval' => 'KISTN_SCHEDULE_INTERVAL',
	);

	/**
	 * Base URL of the inventory server.
	 *
	 * @var string
	 */
	private string $base_url;

	/**
	 * Project UUID used to identify this site on the server.
	 *
	 * @var string
	 */
	private string $project_id;

	/**
	 * Bearer token for authenticating API requests.
	 *
	 * @var string
	 */
	private string $token;

	/**
	 * WPScan API token, or null when not configured.
	 *
	 * @var string|null
	 */
	private ?string $wpscan_token;

	/**
	 * Scheduling mechanism: wp-cron, admin-init, or wp-cli.
	 *
	 * @var string
	 */
	private string $schedule_mode;

	/**
	 * WP-Cron recurrence interval: hourly, twicedaily, or daily.
	 *
	 * @var string
	 */
	private string $schedule_interval;

	/**
	 * Resolves all config values at construction time.
	 *
	 * @param Kistn_Config_Helper $helper Testable helper for constant/option lookups.
	 */
	public function __construct( private readonly Kistn_Config_Helper $helper ) {
		$this->load();
	}

	/**
	 * Re-reads all config values from constants/options. Call after persisting
	 * settings so a same-request validity check reflects the saved state instead
	 * of the snapshot taken at construction time.
	 */
	public function refresh(): void {
		$this->load();
	}

	/**
	 * Resolves all config values from constants and options into this instance.
	 */
	private function load(): void {
		$this->base_url          = $this->resolve( 'base_url', 'kistn_base_url', '' );
		$this->project_id        = $this->resolve( 'project_id', 'kistn_project_id', '' );
		$this->token             = $this->resolve( 'token', 'kistn_token', '' );
		$raw_wpscan              = $this->resolve( 'wpscan_token', 'kistn_wpscan_token', '' );
		$this->wpscan_token      = '' !== $raw_wpscan ? $raw_wpscan : null;
		$this->schedule_mode     = $this->resolve( 'schedule_mode', 'kistn_schedule_mode', 'wp-cron' );
		$this->schedule_interval = $this->resolve( 'schedule_interval', 'kistn_schedule_interval', 'daily' );
	}

	/**
	 * Returns true when all required fields are present and valid.
	 */
	public function is_valid(): bool {
		return '' !== $this->base_url
			&& '' !== $this->project_id
			&& '' !== $this->token
			&& in_array( $this->schedule_mode, self::VALID_SCHEDULE_MODES, true )
			&& in_array( $this->schedule_interval, self::VALID_INTERVALS, true );
	}

	/**
	 * Returns the base URL with any trailing slash removed.
	 */
	public function base_url(): string {
		return rtrim( $this->base_url, '/' );
	}

	/**
	 * Returns the project UUID.
	 */
	public function project_id(): string {
		return $this->project_id;
	}

	/**
	 * Returns the API bearer token.
	 */
	public function token(): string {
		return $this->token;
	}

	/**
	 * Returns the WPScan token, or null when not configured.
	 */
	public function wpscan_token(): ?string {
		return $this->wpscan_token;
	}

	/**
	 * Returns the configured schedule mode.
	 */
	public function schedule_mode(): string {
		return $this->schedule_mode;
	}

	/**
	 * Returns the configured schedule interval.
	 */
	public function schedule_interval(): string {
		return $this->schedule_interval;
	}

	/**
	 * Returns true when the given config key is sourced from a PHP constant.
	 *
	 * @param string $key Config key (e.g. 'base_url', 'token').
	 */
	public function is_constant( string $key ): bool {
		return isset( self::CONSTANT_MAP[ $key ] )
			&& $this->helper->is_constant_defined( self::CONSTANT_MAP[ $key ] );
	}

	/**
	 * Resolves a config value from a constant (priority) or WP option.
	 *
	 * @param string $key      Key into CONSTANT_MAP.
	 * @param string $option   WP option name fallback.
	 * @param string $fallback Value when neither constant nor option is set.
	 */
	private function resolve( string $key, string $option, string $fallback ): string {
		$constant_name = self::CONSTANT_MAP[ $key ] ?? null;

		if ( null !== $constant_name && $this->helper->is_constant_defined( $constant_name ) ) {
			$value = $this->helper->get_constant( $constant_name );
			return is_string( $value ) ? $value : $fallback;
		}

		$value = $this->helper->get_wp_option( $option, $fallback );
		return is_string( $value ) ? $value : $fallback;
	}
}
