<?php
/**
 * Collects all installed WordPress themes.
 *
 * @package Kistn
 */

/**
 * Collects all installed WordPress themes.
 * Both child and parent of the active theme are marked as active.
 */
class Kistn_Theme_Collector implements Kistn_Collector_Interface {

	/**
	 * Collects installed themes and returns them as package descriptors.
	 *
	 * @return array<int, array{name: string, version: string, is_direct: bool, is_dev: bool, is_active: bool, is_child: bool, depth: int, source_url: string|null, available_version: string|null}>
	 */
	public function collect(): array {
		$active_slugs = array_values( array_filter( array( get_stylesheet(), get_template() ) ) );

		$updates   = get_site_transient( 'update_themes' );
		$response  = ( is_object( $updates ) && isset( $updates->response ) && is_array( $updates->response ) ) ? $updates->response : array();
		$no_update = ( is_object( $updates ) && isset( $updates->no_update ) && is_array( $updates->no_update ) ) ? $updates->no_update : array();
		$packages  = array();

		foreach ( wp_get_themes() as $slug => $theme ) {
			$slug_str = (string) $slug;
			$raw_uri  = $theme->get( 'ThemeURI' );
			$template = $theme->get( 'Template' );
			$author   = $theme->get( 'Author' );

			$available = null;
			$entry     = $response[ $slug_str ] ?? $no_update[ $slug_str ] ?? null;
			if ( is_array( $entry ) ) {
				$v         = $entry['new_version'] ?? null;
				$available = ( is_string( $v ) && '' !== $v ) ? $v : null;
			}

			$packages[] = array(
				'name'              => $slug_str,
				'version'           => $theme->get( 'Version' ) ? $theme->get( 'Version' ) : '0.0.0',
				'is_direct'         => true,
				'is_dev'            => false,
				'is_active'         => in_array( $slug_str, $active_slugs, true ),
				'is_child'          => '' !== $template && $template !== $slug_str,
				'depth'             => 0,
				'source_url'        => ( '' !== $raw_uri ) ? $raw_uri : null,
				'available_version' => $available,
				'author'            => ( '' !== $author ) ? $author : null,
			);
		}

		return $packages;
	}
}
