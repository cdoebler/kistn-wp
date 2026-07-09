<?php
/**
 * WPScan API client for vulnerability lookups.
 *
 * @package Kistn
 */

/**
 * Queries the WPScan vulnerability database API. Results cached per slug for 24h.
 * Free tier: 25 requests/day — caching prevents quota exhaustion on every push.
 */
class Kistn_Wpscan_Client {

	private const API_BASE             = 'https://wpscan.com/api/v3';
	private const CACHE_TTL            = DAY_IN_SECONDS;
	private const NOT_FOUND_CACHE_TTL  = 7 * DAY_IN_SECONDS;
	private const RATE_LIMIT_TRANSIENT = 'kistn_wpscan_rate_limited';
	/**
	 * WPScan API token.
	 *
	 * @param string|null $token WPScan API token, or null to disable lookups.
	 */
	public function __construct( private readonly ?string $token ) {}

	/**
	 * Queries WPScan for a list of packages (stale slugs only). Returns version-filtered findings,
	 * raw per-slug advisory payloads for the server cache, and slugs confirmed not in WPScan DB.
	 *
	 * @param  string                                           $ecosystem Ecosystem slug.
	 * @param  array<int, array{name: string, version: string}> $packages  Packages to query.
	 * @return array{
	 *   findings:  array<int, array{package_name: string, package_version: string, advisory_id: string, severity: string}>,
	 *   snapshots: array<int, array{ecosystem: string, name: string, payload: array<int, mixed>}>,
	 *   not_found: list<string>
	 * }
	 */
	public function find_advisories( string $ecosystem, array $packages ): array {
		if ( null === $this->token || array() === $packages ) {
			return array(
				'findings'  => array(),
				'snapshots' => array(),
				'not_found' => array(),
			);
		}

		$findings  = array();
		$snapshots = array();
		$not_found = array();

		foreach ( $packages as $package ) {
			$slug    = $package['name'];
			$version = $package['version'];

			$cache_key = 'kistn_wpscan_' . $ecosystem . '_' . $slug;
			$cached    = get_transient( $cache_key );

			if ( false !== $cached && is_array( $cached ) ) {
				if ( isset( $cached['__not_found'] ) ) {
					// Previously returned 404 — still considered private/unavailable.
					$not_found[] = $slug;
					continue;
				}

				$findings    = array_merge( $findings, $this->parse_vulnerabilities( $slug, $version, $this->cast_vulnerabilities( $cached ) ) );
				$snapshots[] = array(
					'ecosystem' => $ecosystem,
					'name'      => $slug,
					'payload'   => array_values( $cached ),
				);
				continue;
			}

			$raw = $this->fetch_raw( $ecosystem, $slug, $version );

			if ( null === $raw ) {
				continue; // rate limited or transient error — skip, do not cache.
			}

			if ( false === $raw ) {
				// Slug not found in WPScan DB (paid/private package). Cache with sentinel so
				// subsequent runs can distinguish 404 from a real empty-vuln result.
				set_transient( $cache_key, array( '__not_found' => true ), self::NOT_FOUND_CACHE_TTL );
				$not_found[] = $slug;
				continue;
			}

			set_transient( $cache_key, $raw, self::CACHE_TTL );

			$findings    = array_merge( $findings, $this->parse_vulnerabilities( $slug, $version, $this->cast_vulnerabilities( $raw ) ) );
			$snapshots[] = array(
				'ecosystem' => $ecosystem,
				'name'      => $slug,
				'payload'   => array_values( $raw ),
			);
		}

		return compact( 'findings', 'snapshots', 'not_found' );
	}

	/**
	 * Derives findings from server-cached advisory data for the given packages without querying WPScan.
	 *
	 * @param  string                                                                                                $ecosystem Ecosystem slug.
	 * @param  array<int, array{name: string, version: string}>                                                      $packages  Installed packages.
	 * @param  array<int, array{ecosystem: string, name: string, advisories: array<int, mixed>, expires_at: string}> $cached    Server-returned advisory entries.
	 * @return array<int, array{package_name: string, package_version: string, advisory_id: string, severity: string}>
	 */
	public function parse_cached_advisories( string $ecosystem, array $packages, array $cached ): array {
		$findings     = array();
		$cached_index = array();

		foreach ( $cached as $entry ) {
			if ( $entry['ecosystem'] === $ecosystem ) {
				$cached_index[ $entry['name'] ] = $entry['advisories'];
			}
		}

		foreach ( $packages as $package ) {
			$slug    = $package['name'];
			$version = $package['version'];

			if ( ! array_key_exists( $slug, $cached_index ) ) {
				continue;
			}

			$raw      = $this->cast_vulnerabilities( $cached_index[ $slug ] );
			$findings = array_merge( $findings, $this->parse_vulnerabilities( $slug, $version, $raw ) );
		}

		return $findings;
	}

	/**
	 * Fetches the raw vulnerability array for a single slug from the WPScan API.
	 * Returns false for 404 (not in DB — cache with long TTL). Returns null on rate-limit or transient error (do not cache).
	 *
	 * @param  string $ecosystem Ecosystem slug.
	 * @param  string $slug      Plugin/theme slug or 'WordPress' for wp-core.
	 * @param  string $version   Installed version (used for wp-core endpoint URL).
	 * @return array<int, mixed>|false|null
	 */
	private function fetch_raw( string $ecosystem, string $slug, string $version ): array|false|null {
		if ( false !== get_transient( self::RATE_LIMIT_TRANSIENT ) ) {
			return null;
		}

		if ( 'wp-core' === $ecosystem ) {
			$url       = self::API_BASE . '/wordpresses/' . rawurlencode( $version );
			$entry_key = $version;
		} else {
			$type      = 'wp-theme' === $ecosystem ? 'themes' : 'plugins';
			$url       = self::API_BASE . '/' . $type . '/' . rawurlencode( $slug );
			$entry_key = $slug;
		}

		$response = wp_remote_get(
			$url,
			array(
				'headers' => array( 'Authorization' => 'Token token=' . (string) $this->token ),
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Kistn] WPScan error for ' . $slug . ': ' . $response->get_error_message() );
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 429 === $code ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Kistn] WPScan rate limit reached. Blocking WPScan requests until tomorrow (site timezone).' );
			set_transient( self::RATE_LIMIT_TRANSIENT, true, $this->seconds_until_next_day() );
			return null;
		}

		if ( 404 === $code ) {
			return false; // Not in WPScan DB — paid or private package.
		}

		if ( 200 !== $code ) {
			return null;
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return array();
		}

		$entry = $data[ $entry_key ] ?? null;

		if ( ! is_array( $entry ) || ! isset( $entry['vulnerabilities'] ) || ! is_array( $entry['vulnerabilities'] ) ) {
			return array();
		}

		return array_values( $entry['vulnerabilities'] );
	}

	/**
	 * Seconds remaining until local midnight, in the site's configured timezone.
	 */
	private function seconds_until_next_day(): int {
		$now      = new DateTimeImmutable( 'now', wp_timezone() );
		$midnight = $now->modify( 'tomorrow' );

		return max( 1, $midnight->getTimestamp() - $now->getTimestamp() );
	}

	/**
	 * Converts raw vulnerability entries into normalized finding arrays.
	 *
	 * @param string                           $slug            Plugin or theme slug.
	 * @param string                           $version         Installed version.
	 * @param array<int, array<string, mixed>> $vulnerabilities Raw vulnerability list from the API.
	 * @return array<int, array{package_name: string, package_version: string, advisory_id: string, severity: string}>
	 */
	private function parse_vulnerabilities( string $slug, string $version, array $vulnerabilities ): array {
		$findings = array();

		foreach ( $vulnerabilities as $vuln ) {
			$fixed_in = isset( $vuln['fixed_in'] ) && is_string( $vuln['fixed_in'] ) ? $vuln['fixed_in'] : null;

			if ( null !== $fixed_in && version_compare( $version, $fixed_in, '>=' ) ) {
				continue; // Installed version has the fix applied.
			}

			$advisory_id = $this->advisory_id( $vuln );

			if ( null === $advisory_id ) {
				continue;
			}

			$findings[] = array(
				'package_name'    => $slug,
				'package_version' => $version,
				'advisory_id'     => $advisory_id,
				'severity'        => $this->severity( $vuln ),
			);
		}

		return $findings;
	}

	/**
	 * Derives a canonical advisory ID from vulnerability references.
	 *
	 * @param array<string, mixed> $vuln Raw vulnerability entry.
	 * @return string|null Advisory ID string, or null if no usable reference exists.
	 */
	private function advisory_id( array $vuln ): ?string {
		$refs = is_array( $vuln['references'] ?? null ) ? $vuln['references'] : array();

		if ( ! empty( $refs['wpvulndb'] ) && is_array( $refs['wpvulndb'] ) ) {
			$id = reset( $refs['wpvulndb'] );
			return 'wpscan-' . ( is_scalar( $id ) ? (string) $id : '' );
		}

		if ( ! empty( $refs['cve'] ) && is_array( $refs['cve'] ) ) {
			$id = reset( $refs['cve'] );
			return 'CVE-' . ( is_scalar( $id ) ? (string) $id : '' );
		}

		return null;
	}

	/**
	 * Maps CVSS score to a normalized severity string.
	 *
	 * @param array<string, mixed> $vuln Raw vulnerability entry.
	 * @return string One of 'critical', 'high', 'medium', or 'low'.
	 */
	private function severity( array $vuln ): string {
		$cvss = $vuln['cvss'] ?? null;

		if ( ! is_array( $cvss ) || ! isset( $cvss['score'] ) || ! is_numeric( $cvss['score'] ) ) {
			return 'medium';
		}

		$score = (float) $cvss['score'];

		if ( $score >= 9.0 ) {
			return 'critical';
		}

		if ( $score >= 7.0 ) {
			return 'high';
		}

		if ( $score >= 4.0 ) {
			return 'medium';
		}

		return 'low';
	}

	/**
	 * Casts a mixed array from decoded JSON to the typed vulnerabilities shape.
	 *
	 * @param mixed $raw Raw value from json_decode output.
	 * @return array<int, array<string, mixed>>
	 */
	private function cast_vulnerabilities( mixed $raw ): array {
		if ( ! is_array( $raw ) ) {
			return array();
		}

		// phpcs:ignore Generic.Commenting.DocComment.MissingShort
		/** @var array<int, array<string, mixed>> $raw */
		return $raw;
	}
}
