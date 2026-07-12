<?php
/**
 * Admin settings page. Fields are readonly when set via PHP constant.
 *
 * @package Kistn
 */

/**
 * Admin settings page. Fields are readonly when set via PHP constant.
 */
class Kistn_Settings_Page {

	/**
	 * Text field definitions.
	 *
	 * @var array<string, array{label: string, key: string, constant: string, type?: string, placeholder?: string}>
	 */
	private const TEXT_FIELDS = array(
		'kistn_base_url'     => array(
			'label'       => 'API Base URL',
			'key'         => 'base_url',
			'constant'    => 'KISTN_BASE_URL',
			'placeholder' => 'https://kistn.com',
		),
		'kistn_project_id'   => array(
			'label'    => 'Project ID',
			'key'      => 'project_id',
			'constant' => 'KISTN_PROJECT_ID',
		),
		'kistn_token'        => array(
			'label'    => 'API Token',
			'key'      => 'token',
			'constant' => 'KISTN_TOKEN',
			'type'     => 'password',
		),
		'kistn_wpscan_token' => array(
			'label'    => 'WPScan API Token',
			'key'      => 'wpscan_token',
			'constant' => 'KISTN_WPSCAN_TOKEN',
			'type'     => 'password',
		),
	);

	/**
	 * Constructor.
	 *
	 * @param Kistn_Config $config Plugin configuration.
	 */
	public function __construct( private readonly Kistn_Config $config ) {}

	/**
	 * Registers the settings page in the WP admin menu.
	 */
	public function register(): void {
		add_options_page(
			__( 'Kistn', 'kistn' ),
			__( 'Kistn', 'kistn' ),
			'manage_options',
			'kistn',
			array( $this, 'render' )
		);
	}

	/**
	 * Renders the settings page HTML.
	 */
	public function render(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing
		$raw_nonce = isset( $_POST['kistn_nonce'] ) && is_string( $_POST['kistn_nonce'] ) ? sanitize_key( wp_unslash( $_POST['kistn_nonce'] ) ) : '';
		if ( '' !== $raw_nonce && wp_verify_nonce( $raw_nonce, 'kistn_save_settings' ) ) {
			$this->save();
		}

		$raw_last_error = get_option( 'kistn_last_error', '' );
		$last_error     = is_string( $raw_last_error ) ? $raw_last_error : '';
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Kistn Settings', 'kistn' ); ?></h1>

			<?php if ( '' !== $last_error ) : ?>
				<div class="notice notice-error is-dismissible">
					<p><?php echo esc_html( $last_error ); ?></p>
				</div>
			<?php endif; ?>

			<?php if ( ! $this->config->is_valid() ) : ?>
				<div class="notice notice-warning">
					<p><?php esc_html_e( 'Configuration incomplete. Fill in all required fields below.', 'kistn' ); ?></p>
				</div>
			<?php endif; ?>

			<form method="post">
				<?php wp_nonce_field( 'kistn_save_settings', 'kistn_nonce' ); ?>
				<table class="form-table">

					<?php foreach ( self::TEXT_FIELDS as $option => $field ) : ?>
						<?php
						$is_constant   = $this->config->is_constant( $field['key'] );
						$raw_field_val = get_option( $option, '' );
						$field_val     = is_string( $raw_field_val ) ? $raw_field_val : '';
						// Never reflect a stored secret back into the page source. Show an empty
						// field with a placeholder indicating a value is already set; a blank
						// submit keeps the existing value (see save()).
						$is_secret   = 'password' === ( $field['type'] ?? '' );
						$input_val   = $is_secret ? '' : $field_val;
						$placeholder = ( $is_secret && '' !== $field_val ) ? '••••••••' : ( $field['placeholder'] ?? '' );
						?>
						<tr>
							<th><label for="<?php echo esc_attr( $option ); ?>"><?php echo esc_html( $field['label'] ); ?></label></th>
							<td>
								<input
									type="<?php echo esc_attr( $field['type'] ?? 'text' ); ?>"
									id="<?php echo esc_attr( $option ); ?>"
									name="<?php echo esc_attr( $option ); ?>"
									value="<?php echo esc_attr( $input_val ); ?>"
									placeholder="<?php echo esc_attr( $placeholder ); ?>"
									autocomplete="off"
									class="regular-text"
									<?php echo $is_constant ? 'readonly' : ''; ?>
								/>
								<?php if ( $is_constant ) : ?>
									<p class="description">
										<?php
										echo esc_html(
											sprintf(
												/* translators: %s: PHP constant name */
												__( 'Value is set via the %s constant in wp-config.php.', 'kistn' ),
												$field['constant']
											)
										);
										?>
									</p>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>

					<tr>
						<th><label for="kistn_schedule_mode"><?php esc_html_e( 'Schedule Mode', 'kistn' ); ?></label></th>
						<td>
							<?php $mode_locked = $this->config->is_constant( 'schedule_mode' ); ?>
							<select id="kistn_schedule_mode" name="kistn_schedule_mode" <?php echo $mode_locked ? 'disabled' : ''; ?>>
								<?php
								$schedule_modes = array(
									'wp-cron'    => __( 'WP-Cron (fires on page load)', 'kistn' ),
									'admin-init' => __( 'WP-Cron + admin_init (fires on every admin page load)', 'kistn' ),
									'wp-cli'     => __( 'WP-CLI only (manual / server crontab)', 'kistn' ),
								);
								foreach ( $schedule_modes as $value => $label ) :
									?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( get_option( 'kistn_schedule_mode', 'wp-cron' ), $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>

							<?php $current_mode = $this->current_schedule_mode(); ?>

							<div id="kistn-cron-instructions" style="margin-top:10px;padding:10px 14px;background:#f6f7f7;border:1px solid #dcdcde;font-family:monospace;font-size:13px;line-height:1.6<?php echo ( 'wp-cli' !== $current_mode ) ? ';display:none' : ''; ?>">
								<strong><?php esc_html_e( 'Add to your server crontab (example: daily at 2am):', 'kistn' ); ?></strong><br>
								<code>0 2 * * * cd /var/www/html &amp;&amp; wp kistn push</code><br><br>
								<small><?php esc_html_e( 'WP-CLI must be installed on the server. Replace /var/www/html with the absolute path to your WordPress root.', 'kistn' ); ?></small>
							</div>
						</td>
					</tr>

					<tr id="kistn-interval-row" <?php echo ( 'wp-cli' === $current_mode ) ? 'style="display:none"' : ''; ?>>
						<th><label for="kistn_schedule_interval"><?php esc_html_e( 'Push Interval', 'kistn' ); ?></label></th>
						<td>
							<?php $interval_locked = $this->config->is_constant( 'schedule_interval' ); ?>
							<select id="kistn_schedule_interval" name="kistn_schedule_interval" <?php echo $interval_locked ? 'disabled' : ''; ?>>
								<?php
								$schedule_intervals = array(
									'hourly'     => __( 'Hourly', 'kistn' ),
									'twicedaily' => __( 'Twice Daily', 'kistn' ),
									'daily'      => __( 'Daily', 'kistn' ),
								);
								foreach ( $schedule_intervals as $value => $label ) :
									?>
									<option value="<?php echo esc_attr( $value ); ?>" <?php selected( get_option( 'kistn_schedule_interval', 'daily' ), $value ); ?>>
										<?php echo esc_html( $label ); ?>
									</option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>

				</table>
				<script>
				( function () {
					var modeSelect     = document.getElementById( 'kistn_schedule_mode' );
					var intervalRow    = document.getElementById( 'kistn-interval-row' );
					var cronInstructions = document.getElementById( 'kistn-cron-instructions' );

					if ( ! modeSelect || ! intervalRow || ! cronInstructions ) {
						return;
					}

					modeSelect.addEventListener( 'change', function () {
						var isWpCli = ( 'wp-cli' === modeSelect.value );
						intervalRow.style.display = isWpCli ? 'none' : '';
						cronInstructions.style.display = isWpCli ? '' : 'none';
					} );
				} )();
				</script>
				<p class="submit">
					<input type="submit" class="button-primary" value="<?php esc_attr_e( 'Save Settings', 'kistn' ); ?>"/>
				</p>
			</form>
		</div>
		<?php
	}

	/**
	 * Returns the schedule mode as it will be after this request's save() call —
	 * constant-locked values win; otherwise reads the option fresh (not the
	 * possibly-stale value captured in $this->config at construction time).
	 */
	private function current_schedule_mode(): string {
		if ( $this->config->is_constant( 'schedule_mode' ) ) {
			return $this->config->schedule_mode();
		}

		$raw = get_option( 'kistn_schedule_mode', 'wp-cron' );

		return is_string( $raw ) && '' !== $raw ? $raw : 'wp-cron';
	}

	/**
	 * Saves the submitted settings to wp_options.
	 *
	 * Called only after nonce verification in render().
	 */
	private function save(): void {
		$all_fields = array_merge(
			array_keys( self::TEXT_FIELDS ),
			array( 'kistn_schedule_mode', 'kistn_schedule_interval' )
		);

		$key_map = array_merge(
			array_combine(
				array_keys( self::TEXT_FIELDS ),
				array_column( self::TEXT_FIELDS, 'key' )
			),
			array(
				'kistn_schedule_mode'     => 'schedule_mode',
				'kistn_schedule_interval' => 'schedule_interval',
			)
		);

		foreach ( $all_fields as $option ) {
			$key = $key_map[ $option ];

			if ( $this->config->is_constant( $key ) ) {
				continue;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in render() before save() is called.
			if ( ! isset( $_POST[ $option ] ) || ! is_string( $_POST[ $option ] ) ) {
				continue;
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce verified in render().
			$value = sanitize_text_field( wp_unslash( $_POST[ $option ] ) );

			// A blank secret field means "unchanged" — never overwrite the stored token with empty.
			$is_secret = 'password' === ( self::TEXT_FIELDS[ $option ]['type'] ?? '' );
			if ( $is_secret && '' === $value ) {
				continue;
			}

			update_option( $option, $value );
		}

		update_option( 'kistn_last_error', '' );

		// Re-read the just-persisted values so the completeness notice in render()
		// reflects the saved state, not the snapshot taken at construction time.
		$this->config->refresh();

		$this->resync_schedule();
	}

	/**
	 * Clears and re-registers the cron event so a changed mode or interval takes effect immediately.
	 *
	 * Constant-locked values win over options. wp-cli mode registers no cron event.
	 */
	private function resync_schedule(): void {
		$raw_mode     = get_option( 'kistn_schedule_mode', 'wp-cron' );
		$raw_interval = get_option( 'kistn_schedule_interval', 'daily' );

		$mode = $this->config->is_constant( 'schedule_mode' )
			? $this->config->schedule_mode()
			: ( is_string( $raw_mode ) ? $raw_mode : 'wp-cron' );

		$interval = $this->config->is_constant( 'schedule_interval' )
			? $this->config->schedule_interval()
			: ( is_string( $raw_interval ) ? $raw_interval : 'daily' );

		wp_clear_scheduled_hook( 'kistn_run_inventory_push' );

		if ( 'wp-cli' !== $mode && in_array( $interval, array( 'hourly', 'twicedaily', 'daily' ), true ) ) {
			wp_schedule_event( time(), $interval, 'kistn_run_inventory_push' );
		}
	}
}
