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
	 * @return array<int, array{name: string, version: string, is_direct: bool, is_dev: bool, is_active: bool, depth: int, source_url: string|null, available_version: string|null}>
	 */
	public function collect(): array {
		if ( ! function_exists( 'get_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$updates   = get_site_transient( 'update_plugins' );
		$response  = ( is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) ) ? $updates->response : array();
		$no_update = ( is_object( $updates ) && isset( $updates->no_update ) && is_array( $updates->no_update ) ) ? $updates->no_update : array();
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
			);
		}

		return $packages;
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
