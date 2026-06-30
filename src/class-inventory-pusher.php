<?php
/**
 * Inventory pusher orchestrator.
 *
 * @package Kistn
 */

/**
 * Orchestrates preflight → hash-check → collect → WPScan → push for all WP ecosystems.
 */
class Kistn_Inventory_Pusher {

	/**
	 * Constructor.
	 *
	 * @param Kistn_Http_Client         $http             HTTP client for the inventory API.
	 * @param Kistn_Collector_Interface $plugin_collector Collects installed plugins.
	 * @param Kistn_Collector_Interface $theme_collector  Collects installed themes.
	 * @param Kistn_Wpscan_Client       $wpscan           WPScan vulnerability client.
	 * @param Kistn_Collector_Interface $core_collector   Collects WordPress core version.
	 */
	public function __construct(
		private readonly Kistn_Http_Client $http,
		private readonly Kistn_Collector_Interface $plugin_collector,
		private readonly Kistn_Collector_Interface $theme_collector,
		private readonly Kistn_Wpscan_Client $wpscan,
		private readonly Kistn_Collector_Interface $core_collector = new Kistn_Core_Collector(),
	) {}

	/**
	 * Runs preflight then pushes all changed ecosystems in a single bundled request.
	 */
	public function push(): void {
		$core_packages   = $this->core_collector->collect();
		$plugin_packages = $this->plugin_collector->collect();
		$theme_packages  = $this->theme_collector->collect();

		$server_hashes = $this->http->get_hashes();
		$preflight     = $this->run_preflight( $core_packages, $plugin_packages, $theme_packages );
		$stale_by_eco  = $this->group_stale( $preflight['stale'] );
		$cached_by_eco = $this->group_cached( $preflight['advisories'] );
		$private_names = $preflight['private'];

		$ecosystems_map = array(
			'wp-core'   => $core_packages,
			'wp-plugin' => $plugin_packages,
			'wp-theme'  => $theme_packages,
		);

		$payloads = array();

		foreach ( $ecosystems_map as $ecosystem => $packages ) {
			if ( array() === $packages ) {
				continue;
			}

			$content_hash = hash( 'sha256', (string) wp_json_encode( $packages ) );
			$server_hash  = $server_hashes[ $ecosystem ] ?? null;

			if ( $content_hash === $server_hash ) {
				continue;
			}

			$payloads[ $ecosystem ] = $this->build_ecosystem_payload(
				$ecosystem,
				$packages,
				$stale_by_eco[ $ecosystem ] ?? array(),
				$cached_by_eco,
				$private_names,
			);
		}

		if ( array() === $payloads ) {
			return;
		}

		$this->http->push( $payloads );
	}

	/**
	 * Calls the preflight API with all packages from all ecosystems combined.
	 *
	 * @param  array<int, array{name: string, version: string, is_direct: bool, is_dev: bool, is_active: bool, depth: int, source_url: string|null, available_version: string|null}> $core    Collected core packages.
	 * @param  array<int, array{name: string, version: string, is_direct: bool, is_dev: bool, is_active: bool, depth: int, source_url: string|null, available_version: string|null}> $plugins Collected plugin packages.
	 * @param  array<int, array{name: string, version: string, is_direct: bool, is_dev: bool, is_active: bool, depth: int, source_url: string|null, available_version: string|null}> $themes  Collected theme packages.
	 * @return array{
	 *   stale:      array<int, array{ecosystem: string, name: string}>,
	 *   advisories: array<int, array{ecosystem: string, name: string, advisories: array<int, mixed>, expires_at: string}>,
	 *   private:    list<string>
	 * }
	 */
	private function run_preflight( array $core, array $plugins, array $themes ): array {
		$all = array_merge(
			array_map(
				static fn( array $p ): array => array(
					'ecosystem' => 'wp-core',
					'name'      => $p['name'],
					'version'   => $p['version'],
				),
				$core
			),
			array_map(
				static fn( array $p ): array => array(
					'ecosystem' => 'wp-plugin',
					'name'      => $p['name'],
					'version'   => $p['version'],
				),
				$plugins
			),
			array_map(
				static fn( array $p ): array => array(
					'ecosystem' => 'wp-theme',
					'name'      => $p['name'],
					'version'   => $p['version'],
				),
				$themes
			),
		);

		if ( array() === $all ) {
			return array(
				'stale'      => array(),
				'advisories' => array(),
				'private'    => array(),
			);
		}

		return $this->http->preflight( $all );
	}

	/**
	 * Builds the push payload for a single ecosystem.
	 *
	 * @param string                                                                                                                                                                $ecosystem     Ecosystem slug.
	 * @param array<int, array{name: string, version: string, is_direct: bool, is_dev: bool, is_active: bool, depth: int, source_url: string|null, available_version: string|null}> $packages      Collected packages for this ecosystem.
	 * @param array<int, string>                                                                                                                                                    $stale_names   Slug names that need WPScan queries.
	 * @param array<string, array<int, array{ecosystem: string, name: string, advisories: array<int, mixed>, expires_at: string}>>                                                  $cached_by_eco All cached advisories grouped by ecosystem.
	 * @param string[]                                                                                                                                                              $private_names Server-confirmed private slugs — skip WPScan entirely.
	 * @return array{packages: array<int, array<string, mixed>>, findings: array<int, array<string, mixed>>, advisories: array<int, array<string, mixed>>, private_packages: string[]}
	 */
	private function build_ecosystem_payload(
		string $ecosystem,
		array $packages,
		array $stale_names,
		array $cached_by_eco,
		array $private_names = array(),
	): array {
		// Packages without a source_url have no public web presence — treat as private without querying WPScan.
		$no_url_private = array_values(
			array_map(
				static fn( array $p ): string => $p['name'],
				array_filter(
					$packages,
					static fn( array $p ): bool =>
					'wp-core' !== $ecosystem
					&& null === ( $p['source_url'] ?? null )
					&& ! in_array( $p['name'], $private_names, true )
				)
			)
		);

		$stale_packages = array_values(
			array_filter(
				$packages,
				static fn( array $p ): bool =>
					in_array( $p['name'], $stale_names, true )
					&& ! in_array( $p['name'], $private_names, true )
					&& ( 'wp-core' === $ecosystem || null !== ( $p['source_url'] ?? null ) )
			)
		);

		$wpscan_result = $this->wpscan->find_advisories(
			$ecosystem,
			array_map(
				static fn( array $p ): array => array(
					'name'    => $p['name'],
					'version' => $p['version'],
				),
				$stale_packages
			)
		);

		$cached_findings = $this->wpscan->parse_cached_advisories(
			$ecosystem,
			array_map(
				static fn( array $p ): array => array(
					'name'    => $p['name'],
					'version' => $p['version'],
				),
				$packages
			),
			$cached_by_eco[ $ecosystem ] ?? array(),
		);

		return array(
			'packages'         => $packages,
			'findings'         => array_merge( $wpscan_result['findings'], $cached_findings ),
			'advisories'       => $wpscan_result['snapshots'],
			'private_packages' => array_values( array_unique( array_merge( $wpscan_result['not_found'], $no_url_private ) ) ),
		);
	}

	/**
	 * Groups stale package entries by ecosystem slug.
	 *
	 * @param  array<int, array{ecosystem: string, name: string}> $stale Stale entries from preflight.
	 * @return array<string, array<int, string>>
	 */
	private function group_stale( array $stale ): array {
		$by_eco = array();
		foreach ( $stale as $item ) {
			$by_eco[ $item['ecosystem'] ][] = $item['name'];
		}
		return $by_eco;
	}

	/**
	 * Groups cached advisory entries by ecosystem slug.
	 *
	 * @param  array<int, array{ecosystem: string, name: string, advisories: array<int, mixed>, expires_at: string}> $advisories Cached advisory entries from preflight.
	 * @return array<string, array<int, array{ecosystem: string, name: string, advisories: array<int, mixed>, expires_at: string}>>
	 */
	private function group_cached( array $advisories ): array {
		$by_eco = array();
		foreach ( $advisories as $item ) {
			$by_eco[ $item['ecosystem'] ][] = $item;
		}
		return $by_eco;
	}
}
