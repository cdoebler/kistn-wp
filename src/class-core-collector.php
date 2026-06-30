<?php
/**
 * Collects WordPress core as a package.
 *
 * @package Kistn
 */

/**
 * Collects WordPress core version as a single package descriptor.
 */
class Kistn_Core_Collector implements Kistn_Collector_Interface {

	/**
	 * Returns WordPress core as a package descriptor.
	 *
	 * @return array<int, array{name: string, version: string, is_direct: bool, is_dev: bool, is_active: bool, depth: int, source_url: string|null, available_version: string|null}>
	 */
	public function collect(): array {
		$update_core = get_site_transient( 'update_core' );
		$available   = null;

		if ( is_object( $update_core ) && isset( $update_core->updates ) && is_array( $update_core->updates ) ) {
			foreach ( $update_core->updates as $update ) {
				if ( ! is_object( $update ) ) {
					continue;
				}
				if ( ( $update->response ?? null ) === 'development' ) {
					continue;
				}
				$v         = $update->current ?? $update->version ?? null;
				$available = ( is_string( $v ) && '' !== $v ) ? $v : null;
				break;
			}
		}

		return array(
			array(
				'name'              => 'wordpress',
				'version'           => get_bloginfo( 'version' ),
				'is_direct'         => true,
				'is_dev'            => false,
				'is_active'         => true,
				'depth'             => 0,
				'source_url'        => null,
				'available_version' => $available,
			),
		);
	}
}
