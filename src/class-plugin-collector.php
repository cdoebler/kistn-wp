<?php
/**
 * Collects all installed WordPress plugins with active status.
 *
 * @package Kistn
 */

/**
 * Collects all installed WordPress plugins with active status.
 */
class Kistn_Plugin_Collector implements Kistn_Collector_Interface {

	/**
	 * Collects installed plugins and returns them as package descriptors.
	 *
	 * @return array<int, array{name: string, version: string, is_direct: bool, is_dev: bool, is_active: bool, depth: int, source_url: string|null, available_version: string|null, author: string|null, in_directory: bool|null}>
	 */
	public function collect(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$updates   = get_site_transient( 'update_plugins' );
		$response  = ( is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) ) ? $updates->response : array();
		$no_update = ( is_object( $updates ) && isset( $updates->no_update ) && is_array( $updates->no_update ) ) ? $updates->no_update : array();
		$checked   = ( is_object( $updates ) && isset( $updates->checked ) && is_array( $updates->checked ) ) ? $updates->checked : array();
		$packages  = array();

		foreach ( get_plugins() as $file => $data ) {
			$version = ( is_string( $data['Version'] ?? null ) && '' !== $data['Version'] ) ? $data['Version'] : '0.0.0';
			$raw_uri = $data['PluginURI'] ?? '';

			$available = null;
			$entry     = $response[ $file ] ?? $no_update[ $file ] ?? null;
			if ( is_object( $entry ) ) {
				$v         = $entry->new_version ?? null;
				$available = ( is_string( $v ) && '' !== $v ) ? $v : null;
			}

			$packages[] = array(
				'name'              => $this->slug_from_file( $file ),
				'version'           => $version,
				'is_direct'         => true,
				'is_dev'            => false,
				'is_active'         => (bool) is_plugin_active( $file ),
				'depth'             => 0,
				'source_url'        => ( is_string( $raw_uri ) && '' !== $raw_uri ) ? $raw_uri : null,
				'available_version' => $available,
				'author'            => ( is_string( $data['Author'] ?? null ) && '' !== $data['Author'] ) ? $data['Author'] : null,
				'in_directory'      => $this->registry_membership( $file, $response, $no_update, $checked ),
			);
		}

		return $packages;
	}

	/**
	 * Determines wordpress.org directory membership from WP core's own update
	 * transient — the authoritative, already-cached answer. Presence in `response`
	 * (update available) or `no_update` (current) means the package is hosted on
	 * wordpress.org (public). A checked-but-absent package is confirmed private.
	 * A `null` result means WP has not run an update check yet (membership unknown).
	 *
	 * @param string       $key       Transient key for this package (plugin file / theme stylesheet).
	 * @param array<mixed> $response  update transient `response` list.
	 * @param array<mixed> $no_update update transient `no_update` list.
	 * @param array<mixed> $checked   update transient `checked` list.
	 */
	private function registry_membership( string $key, array $response, array $no_update, array $checked ): ?bool {
		if ( isset( $response[ $key ] ) || isset( $no_update[ $key ] ) ) {
			return true;
		}

		if ( isset( $checked[ $key ] ) ) {
			return false;
		}

		return null;
	}

	/**
	 * Derives a slug from a plugin file path.
	 *
	 * For single-file plugins (e.g. hello.php) the slug is the filename without
	 * extension. For directory-based plugins (e.g. akismet/akismet.php) the slug
	 * is the directory name.
	 *
	 * @param string $plugin_file Plugin file relative to the plugins directory.
	 */
	private function slug_from_file( string $plugin_file ): string {
		if ( ! str_contains( $plugin_file, '/' ) ) {
			return pathinfo( $plugin_file, PATHINFO_FILENAME );
		}

		return explode( '/', $plugin_file )[0];
	}
}
