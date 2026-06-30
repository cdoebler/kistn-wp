<?php
/**
 * HTTP client for the kistn API.
 *
 * @package Kistn
 */

/**
 * Wraps wp_remote_* calls for the kistn API.
 */
class Kistn_Http_Client {

	/**
	 * Resolved plugin configuration.
	 *
	 * @param Kistn_Config $config Plugin configuration.
	 */
	public function __construct( private readonly Kistn_Config $config ) {}

	/**
	 * Fetches all ecosystem inventory hashes in one request.
	 *
	 * @return array<string, string|null> Map of ecosystem slug to hash, or empty array on error.
	 */
	public function get_hashes(): array {
		$url      = $this->endpoint( 'hashes' );
		$response = wp_remote_get( $url, $this->default_args() );

		if ( is_wp_error( $response ) ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( '[Kistn] get_hashes error: ' . $response->get_error_message() );
			return array();
		}

		if ( (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return array();
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return array();
		}

		$result = array();
		foreach ( $data as $ecosystem => $hash ) {
			$result[ (string) $ecosystem ] = is_string( $hash ) ? $hash : null;
		}

		return $result;
	}

	/**
	 * Checks the server advisory cache for the given packages.
	 * Returns stale slugs (must query WPScan), fresh cached advisories (can skip WPScan),
	 * and server-confirmed private package names (must skip WPScan entirely).
	 * Falls back to marking all packages stale on network error.
	 *
	 * @param  array<int, array{ecosystem: string, name: string, version: string}> $packages Packages to check against the server advisory cache.
	 * @return array{
	 *   stale:      array<int, array{ecosystem: string, name: string}>,
	 *   advisories: array<int, array{ecosystem: string, name: string, advisories: array<int, mixed>, expires_at: string}>,
	 *   private:    list<string>
	 * }
	 */
	public function preflight( array $packages ): array {
		$url      = $this->config->base_url()
			. '/api/projects/'
			. $this->config->project_id()
			. '/preflight/wp';
		$args     = $this->json_post_args( array( 'packages' => $packages ) );
		$response = wp_remote_post( $url, $args );

		$all_stale = array_map(
			static fn( array $p ): array => array(
				'ecosystem' => $p['ecosystem'],
				'name'      => $p['name'],
			),
			$packages
		);

		if ( is_wp_error( $response ) || (int) wp_remote_retrieve_response_code( $response ) !== 200 ) {
			return array(
				'stale'      => $all_stale,
				'advisories' => array(),
				'private'    => array(),
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $data ) ) {
			return array(
				'stale'      => $all_stale,
				'advisories' => array(),
				'private'    => array(),
			);
		}

		$stale = $all_stale;
		if ( is_array( $data['stale'] ?? null ) ) {
			$stale_names = array();
			foreach ( $data['stale'] as $item ) {
				if ( is_array( $item ) && is_string( $item['name'] ?? null ) ) {
					$stale_names[] = $item['name'];
				}
			}
			$stale = array_values(
				array_filter(
					$all_stale,
					static fn( array $item ): bool => in_array( $item['name'], $stale_names, true ),
				)
			);
		}

		$advisories = array();
		if ( is_array( $data['advisories'] ?? null ) ) {
			foreach ( $data['advisories'] as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				if ( ! is_string( $item['ecosystem'] ?? null ) || ! is_string( $item['name'] ?? null ) ) {
					continue;
				}
				if ( ! is_array( $item['advisories'] ?? null ) || ! is_string( $item['expires_at'] ?? null ) ) {
					continue;
				}
				$advisories[] = array(
					'ecosystem'  => $item['ecosystem'],
					'name'       => $item['name'],
					'advisories' => array_values( $item['advisories'] ),
					'expires_at' => $item['expires_at'],
				);
			}
		}

		$private = array();
		if ( is_array( $data['private'] ?? null ) ) {
			foreach ( $data['private'] as $name ) {
				if ( is_string( $name ) ) {
					$private[] = $name;
				}
			}
		}

		return array(
			'stale'      => $stale,
			'advisories' => $advisories,
			'private'    => $private,
		);
	}

	/**
	 * Pushes package inventory and vulnerability findings for all changed ecosystems in one request.
	 *
	 * @param array<string, array{packages: array<int, array<string, mixed>>, findings: array<int, array<string, mixed>>, advisories: array<int, array<string, mixed>>, private_packages: string[]}> $ecosystems Map of ecosystem slug to payload.
	 */
	public function push( array $ecosystems ): void {
		$url  = $this->endpoint( 'inventory' );
		$args = $this->json_post_args( array( 'ecosystems' => $ecosystems ) );

		$response = wp_remote_post( $url, $args );

		if ( is_wp_error( $response ) ) {
			$message = '[Kistn] push error: ' . $response->get_error_message();
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $message );
			update_option( 'kistn_last_error', $message );
			return;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 202 !== $code && 200 !== $code ) {
			$message = '[Kistn] push unexpected status ' . $code . ': ' . wp_remote_retrieve_body( $response );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
			error_log( $message );
			update_option( 'kistn_last_error', $message );
		}
	}

	/**
	 * Returns default request arguments including the Authorization header.
	 *
	 * @return array{headers: array<string, string>, timeout: int}
	 */
	private function default_args(): array {
		return array(
			'headers' => array( 'Authorization' => 'Bearer ' . $this->config->token() ),
			'timeout' => 15,
		);
	}

	/**
	 * Builds POST request arguments with a JSON body and the JSON content-type header.
	 *
	 * @param  array<string, mixed> $body Payload to JSON-encode.
	 * @return array{headers: array<string, string>, timeout: int, method: string, body: string}
	 */
	private function json_post_args( array $body ): array {
		$args                            = $this->default_args();
		$args['method']                  = 'POST';
		$args['headers']['Content-Type'] = 'application/json';
		$args['body']                    = (string) wp_json_encode( $body );

		return $args;
	}

	/**
	 * Builds a full API endpoint URL.
	 *
	 * @param string $path Path relative to the project base (e.g. 'hashes').
	 */
	private function endpoint( string $path ): string {
		return $this->config->base_url()
			. '/api/projects/'
			. $this->config->project_id()
			. '/'
			. $path;
	}
}
