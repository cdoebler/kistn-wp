<?php

use Brain\Monkey\Functions;

test('returns empty array when token is null', function () {
    $result = ( new Kistn_Wpscan_Client( null ) )->find_advisories( 'wp-plugin', [ [ 'name' => 'akismet', 'version' => '1.0' ] ] );

    expect( $result['findings'] )->toBe( [] );
    expect( $result['snapshots'] )->toBe( [] );
});

test('returns empty array for empty packages list', function () {
    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-plugin', [] );

    expect( $result['findings'] )->toBe( [] );
    expect( $result['snapshots'] )->toBe( [] );
});

test('returns cached findings without HTTP call', function () {
    $raw_vulns = [ [
        'references' => [ 'wpvulndb' => [ 'abc' ], 'cve' => [] ],
        'cvss'       => [ 'score' => '7.5' ],
        'fixed_in'   => '5.3.0',
    ] ];

    Functions\expect( 'get_transient' )->with( 'kistn_wpscan_wp-plugin_akismet' )->andReturn( $raw_vulns );
    Functions\expect( 'wp_remote_get' )->never();

    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-plugin', [ [ 'name' => 'akismet', 'version' => '5.3.1' ] ] );

    expect( $result['findings'] )->toBe( [] ); // 5.3.1 >= fixed_in 5.3.0 → patched
    expect( $result['snapshots'] )->toHaveCount( 1 );
    expect( $result['snapshots'][0]['name'] )->toBe( 'akismet' );
});

test('fetches from API on cache miss and caches result', function () {
    $api_response = json_encode( [
        'akismet' => [
            'vulnerabilities' => [
                [
                    'references' => [ 'wpvulndb' => [ 'abc123' ], 'cve' => [] ],
                    'cvss'       => [ 'score' => '7.5' ],
                    'fixed_in'   => '5.3.0',
                ],
            ],
        ],
    ] );

    Functions\expect( 'get_transient' )->with( 'kistn_wpscan_wp-plugin_akismet' )->andReturn( false );
    Functions\expect( 'wp_remote_get' )
        ->once()
        ->with(
            'https://wpscan.com/api/v3/plugins/akismet',
            Mockery::subset( [ 'headers' => [ 'Authorization' => 'Token token=tok' ] ] )
        )
        ->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
    Functions\expect( 'wp_remote_retrieve_body' )->andReturn( $api_response );
    Functions\expect( 'set_transient' )->once()->with( 'kistn_wpscan_wp-plugin_akismet', Mockery::any(), 86400 );

    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-plugin', [ [ 'name' => 'akismet', 'version' => '5.3.1' ] ] );

    expect( $result['findings'] )->toBe( [] ); // 5.3.1 >= fixed_in 5.3.0 → patched
    expect( $result['snapshots'] )->toHaveCount( 1 );
});

test('returns empty array on 429 rate limit and logs error', function () {
    Functions\expect( 'get_transient' )->with( 'kistn_wpscan_wp-plugin_akismet' )->andReturn( false );
    Functions\expect( 'get_transient' )->with( 'kistn_wpscan_rate_limited' )->andReturn( false );
    Functions\expect( 'wp_remote_get' )->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 429 );
    Functions\expect( 'wp_timezone' )->andReturn( new DateTimeZone( 'UTC' ) );
    Functions\expect( 'set_transient' )->once()->with( 'kistn_wpscan_rate_limited', true, Mockery::type( 'int' ) );
    Functions\expect( 'error_log' )->once();

    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-plugin', [ [ 'name' => 'akismet', 'version' => '5.3.1' ] ] );

    expect( $result['findings'] )->toBe( [] );
    expect( $result['snapshots'] )->toBe( [] );
});

test('429 rate limit blocks further WPScan calls within the same run', function () {
    $rate_limited = false;

    Functions\when( 'get_transient' )->alias( function ( string $key ) use ( &$rate_limited ) {
        return 'kistn_wpscan_rate_limited' === $key ? $rate_limited : false;
    } );
    Functions\when( 'set_transient' )->alias( function ( string $key, mixed $value ) use ( &$rate_limited ) {
        if ( 'kistn_wpscan_rate_limited' === $key ) {
            $rate_limited = $value;
        }
    } );
    Functions\expect( 'wp_remote_get' )->once()->andReturn( [] );
    Functions\when( 'is_wp_error' )->justReturn( false );
    Functions\when( 'wp_remote_retrieve_response_code' )->justReturn( 429 );
    Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'UTC' ) );
    Functions\when( 'error_log' )->justReturn();

    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-plugin', [
        [ 'name' => 'akismet', 'version' => '1.0' ],
        [ 'name' => 'jetpack', 'version' => '1.0' ],
    ] );

    expect( $result['findings'] )->toBe( [] );
});

test('skips WPScan API call entirely when already rate limited from a previous run', function () {
    Functions\when( 'get_transient' )->alias( function ( string $key ) {
        return 'kistn_wpscan_rate_limited' === $key ? true : false;
    } );
    Functions\expect( 'wp_remote_get' )->never();

    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-plugin', [ [ 'name' => 'akismet', 'version' => '1.0' ] ] );

    expect( $result['findings'] )->toBe( [] );
    expect( $result['snapshots'] )->toBe( [] );
});

test('uses themes endpoint for wp-theme ecosystem', function () {
    Functions\expect( 'get_transient' )->andReturn( false );
    Functions\expect( 'wp_remote_get' )
        ->once()
        ->with( 'https://wpscan.com/api/v3/themes/storefront', Mockery::any() )
        ->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
    Functions\expect( 'wp_remote_retrieve_body' )->andReturn( '{"storefront":{"vulnerabilities":[]}}' );
    Functions\expect( 'set_transient' )->once();

    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-theme', [ [ 'name' => 'storefront', 'version' => '4.5.0' ] ] );

    expect( $result['findings'] )->toBe( [] );
    expect( $result['snapshots'] )->toHaveCount( 1 );
});

test('excludes vuln when installed version is equal to fixed_in', function () {
    $body = (string) json_encode( [
        'akismet' => [
            'vulnerabilities' => [ [
                'references' => [ 'wpvulndb' => [ 'abc' ], 'cve' => [] ],
                'cvss'       => [ 'score' => '7.5' ],
                'fixed_in'   => '5.3.1', // installed == fixed_in → patched
            ] ],
        ],
    ] );

    Functions\expect( 'get_transient' )->andReturn( false );
    Functions\expect( 'wp_remote_get' )->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
    Functions\expect( 'wp_remote_retrieve_body' )->andReturn( $body );
    Functions\expect( 'set_transient' )->once();

    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-plugin', [ [ 'name' => 'akismet', 'version' => '5.3.1' ] ] );

    expect( $result['findings'] )->toBe( [] );
});

test('excludes vuln when installed version is greater than fixed_in', function () {
    $body = (string) json_encode( [
        'akismet' => [
            'vulnerabilities' => [ [
                'references' => [ 'wpvulndb' => [ 'abc' ], 'cve' => [] ],
                'cvss'       => [ 'score' => '7.5' ],
                'fixed_in'   => '5.3.0', // installed 5.3.1 > 5.3.0 → patched
            ] ],
        ],
    ] );

    Functions\expect( 'get_transient' )->andReturn( false );
    Functions\expect( 'wp_remote_get' )->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
    Functions\expect( 'wp_remote_retrieve_body' )->andReturn( $body );
    Functions\expect( 'set_transient' )->once();

    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-plugin', [ [ 'name' => 'akismet', 'version' => '5.3.1' ] ] );

    expect( $result['findings'] )->toBe( [] );
});

test('includes vuln when installed version is less than fixed_in', function () {
    $body = (string) json_encode( [
        'akismet' => [
            'vulnerabilities' => [ [
                'references' => [ 'wpvulndb' => [ 'abc' ], 'cve' => [] ],
                'cvss'       => [ 'score' => '7.5' ],
                'fixed_in'   => '5.4.0', // installed 5.3.1 < 5.4.0 → vulnerable
            ] ],
        ],
    ] );

    Functions\expect( 'get_transient' )->andReturn( false );
    Functions\expect( 'wp_remote_get' )->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
    Functions\expect( 'wp_remote_retrieve_body' )->andReturn( $body );
    Functions\expect( 'set_transient' )->once();

    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-plugin', [ [ 'name' => 'akismet', 'version' => '5.3.1' ] ] );

    expect( $result['findings'] )->toHaveCount( 1 );
});

test('includes vuln when fixed_in is absent (no fix released yet)', function () {
    $body = (string) json_encode( [
        'akismet' => [
            'vulnerabilities' => [ [
                'references' => [ 'wpvulndb' => [ 'abc' ], 'cve' => [] ],
                'cvss'       => [ 'score' => '7.5' ],
                // no fixed_in field
            ] ],
        ],
    ] );

    Functions\expect( 'get_transient' )->andReturn( false );
    Functions\expect( 'wp_remote_get' )->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
    Functions\expect( 'wp_remote_retrieve_body' )->andReturn( $body );
    Functions\expect( 'set_transient' )->once();

    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-plugin', [ [ 'name' => 'akismet', 'version' => '5.3.1' ] ] );

    expect( $result['findings'] )->toHaveCount( 1 );
});

test('find_advisories returns findings and snapshot for vulnerable plugin', function () {
    $body = (string) json_encode( [
        'akismet' => [
            'vulnerabilities' => [ [
                'references' => [ 'wpvulndb' => [ 'abc' ], 'cve' => [] ],
                'cvss'       => [ 'score' => '7.5' ],
                'fixed_in'   => '5.4.0',
            ] ],
        ],
    ] );

    Functions\expect( 'get_transient' )->with( 'kistn_wpscan_wp-plugin_akismet' )->andReturn( false );
    Functions\expect( 'wp_remote_get' )->once()->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
    Functions\expect( 'wp_remote_retrieve_body' )->andReturn( $body );
    Functions\expect( 'set_transient' )->once()->with( 'kistn_wpscan_wp-plugin_akismet', Mockery::any(), 86400 );

    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-plugin', [ [ 'name' => 'akismet', 'version' => '5.3.1' ] ] );

    expect( $result['findings'] )->toHaveCount( 1 );
    expect( $result['findings'][0]['advisory_id'] )->toBe( 'wpscan-abc' );
    expect( $result['snapshots'] )->toHaveCount( 1 );
    expect( $result['snapshots'][0]['ecosystem'] )->toBe( 'wp-plugin' );
    expect( $result['snapshots'][0]['name'] )->toBe( 'akismet' );
    expect( $result['snapshots'][0]['payload'] )->toHaveCount( 1 );
});

test('find_advisories includes slug in snapshots even when no vulnerabilities found', function () {
    Functions\expect( 'get_transient' )->andReturn( false );
    Functions\expect( 'wp_remote_get' )->once()->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
    Functions\expect( 'wp_remote_retrieve_body' )->andReturn( '{"clean-plugin":{"vulnerabilities":[]}}' );
    Functions\expect( 'set_transient' )->once();

    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-plugin', [ [ 'name' => 'clean-plugin', 'version' => '1.0.0' ] ] );

    expect( $result['findings'] )->toBe( [] );
    expect( $result['snapshots'] )->toHaveCount( 1 );
    expect( $result['snapshots'][0]['payload'] )->toBe( [] );
});

test('find_advisories excludes slug from snapshots on API error', function () {
    Functions\expect( 'get_transient' )->andReturn( false );
    Functions\expect( 'wp_remote_get' )->once()->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 500 );

    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-plugin', [ [ 'name' => 'error-plugin', 'version' => '1.0.0' ] ] );

    expect( $result['findings'] )->toBe( [] );
    expect( $result['snapshots'] )->toBe( [] );
});

test('find_advisories uses wordpresses endpoint for wp-core', function () {
    Functions\expect( 'get_transient' )->andReturn( false );
    Functions\expect( 'wp_remote_get' )
        ->once()
        ->with( 'https://wpscan.com/api/v3/wordpresses/6.4.2', Mockery::any() )
        ->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
    Functions\expect( 'wp_remote_retrieve_body' )->andReturn( '{"6.4.2":{"vulnerabilities":[]}}' );
    Functions\expect( 'set_transient' )->once();

    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-core', [ [ 'name' => 'wordpress', 'version' => '6.4.2' ] ] );

    expect( $result['snapshots'] )->toHaveCount( 1 );
    expect( $result['snapshots'][0]['name'] )->toBe( 'wordpress' );
});

test('parse_cached_advisories derives findings for installed version', function () {
    $cached = [ [
        'ecosystem'  => 'wp-plugin',
        'name'       => 'woocommerce',
        'advisories' => [ [
            'references' => [ 'wpvulndb' => [ 'vuln1' ], 'cve' => [] ],
            'cvss'       => [ 'score' => '7.5' ],
            'fixed_in'   => '8.1.0', // installed 8.0.0 < 8.1.0 → vulnerable
        ] ],
        'expires_at' => '2026-06-13T15:00:00+00:00',
    ] ];

    $findings = ( new Kistn_Wpscan_Client( 'tok' ) )->parse_cached_advisories(
        'wp-plugin',
        [ [ 'name' => 'woocommerce', 'version' => '8.0.0' ] ],
        $cached
    );

    expect( $findings )->toHaveCount( 1 );
    expect( $findings[0] )->toBe( [
        'package_name'    => 'woocommerce',
        'package_version' => '8.0.0',
        'advisory_id'     => 'wpscan-vuln1',
        'severity'        => 'high',
    ] );
});

test('parse_cached_advisories excludes patched vulns for installed version', function () {
    $cached = [ [
        'ecosystem'  => 'wp-plugin',
        'name'       => 'woocommerce',
        'advisories' => [ [
            'references' => [ 'wpvulndb' => [ 'vuln1' ], 'cve' => [] ],
            'cvss'       => [ 'score' => '7.5' ],
            'fixed_in'   => '8.0.0', // installed 8.0.0 >= 8.0.0 → patched
        ] ],
        'expires_at' => '2026-06-13T15:00:00+00:00',
    ] ];

    $findings = ( new Kistn_Wpscan_Client( 'tok' ) )->parse_cached_advisories(
        'wp-plugin',
        [ [ 'name' => 'woocommerce', 'version' => '8.0.0' ] ],
        $cached
    );

    expect( $findings )->toBe( [] );
});

test('find_advisories caches sentinel on 404 and reports not_found without snapshot', function () {
    Functions\expect( 'get_transient' )->with( 'kistn_wpscan_wp-plugin_private-plugin' )->andReturn( false );
    Functions\expect( 'wp_remote_get' )->once()->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 404 );
    Functions\expect( 'set_transient' )->once()->with( 'kistn_wpscan_wp-plugin_private-plugin', [ '__not_found' => true ], 7 * 86400 );

    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-plugin', [ [ 'name' => 'private-plugin', 'version' => '1.0.0' ] ] );

    expect( $result['findings'] )->toBe( [] );
    expect( $result['snapshots'] )->toBe( [] );
    expect( $result['not_found'] )->toBe( [ 'private-plugin' ] );
});

test('find_advisories reads sentinel from cache and adds to not_found without querying API', function () {
    Functions\expect( 'get_transient' )->with( 'kistn_wpscan_wp-plugin_private-plugin' )->andReturn( [ '__not_found' => true ] );
    Functions\expect( 'wp_remote_get' )->never();

    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-plugin', [ [ 'name' => 'private-plugin', 'version' => '1.0.0' ] ] );

    expect( $result['findings'] )->toBe( [] );
    expect( $result['snapshots'] )->toBe( [] );
    expect( $result['not_found'] )->toBe( [ 'private-plugin' ] );
});

test('severity mapping from cvss score', function () {
    $cases = [
        [ 'score' => '9.8', 'expected' => 'critical' ],
        [ 'score' => '7.0', 'expected' => 'high' ],
        [ 'score' => '4.0', 'expected' => 'medium' ],
        [ 'score' => '3.9', 'expected' => 'low' ],
    ];

    $bodies = array_map( static function ( array $case ): string {
        return (string) json_encode( [
            'test-plugin' => [
                'vulnerabilities' => [ [
                    'references' => [ 'wpvulndb' => [ 'id1' ], 'cve' => [] ],
                    'cvss'       => [ 'score' => $case['score'] ],
                ] ],
            ],
        ] );
    }, $cases );

    $bodyIndex = 0;

    Functions\expect( 'get_transient' )->times( 8 )->andReturn( false );
    Functions\expect( 'wp_remote_get' )->times( 4 )->andReturn( [] );
    Functions\expect( 'is_wp_error' )->times( 4 )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->times( 4 )->andReturn( 200 );
    Functions\expect( 'wp_remote_retrieve_body' )->times( 4 )->andReturnUsing(
        static function () use ( $bodies, &$bodyIndex ): string {
            return $bodies[ $bodyIndex++ ];
        }
    );
    Functions\expect( 'set_transient' )->times( 4 );

    foreach ( $cases as $case ) {
        $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-plugin', [ [ 'name' => 'test-plugin', 'version' => '0.9.0' ] ] );

        expect( $result['findings'][0]['severity'] )->toBe( $case['expected'] );
    }
});

test('parse_cached_advisories skips package not present in cached advisory index', function () {
    $cached = [ [
        'ecosystem'  => 'wp-plugin',
        'name'       => 'known-plugin',
        'advisories' => [],
        'expires_at' => '2026-06-13T15:00:00+00:00',
    ] ];

    $findings = ( new Kistn_Wpscan_Client( 'tok' ) )->parse_cached_advisories(
        'wp-plugin',
        [ [ 'name' => 'unknown-plugin', 'version' => '1.0.0' ] ],
        $cached
    );

    expect( $findings )->toBe( [] );
});

test('find_advisories skips slug and logs when WPScan returns a network error', function () {
    Functions\expect( 'get_transient' )->andReturn( false );
    Functions\expect( 'wp_remote_get' )->andReturn( new WP_Error() );
    Functions\expect( 'is_wp_error' )->andReturn( true );
    Functions\expect( 'error_log' )->once();

    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-plugin', [ [ 'name' => 'akismet', 'version' => '1.0.0' ] ] );

    expect( $result['findings'] )->toBe( [] );
    expect( $result['snapshots'] )->toBe( [] );
    expect( $result['not_found'] )->toBe( [] );
});

test('find_advisories treats non-array API body as empty vulnerability list', function () {
    Functions\expect( 'get_transient' )->andReturn( false );
    Functions\expect( 'wp_remote_get' )->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
    Functions\expect( 'wp_remote_retrieve_body' )->andReturn( '"not-an-array"' );
    Functions\expect( 'set_transient' )->once()->with( 'kistn_wpscan_wp-plugin_akismet', [], 86400 );

    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-plugin', [ [ 'name' => 'akismet', 'version' => '1.0.0' ] ] );

    expect( $result['findings'] )->toBe( [] );
    expect( $result['snapshots'] )->toHaveCount( 1 );
});

test('find_advisories treats entry missing vulnerabilities key as no findings', function () {
    $body = (string) json_encode( [ 'akismet' => [ 'slug' => 'akismet' ] ] );

    Functions\expect( 'get_transient' )->andReturn( false );
    Functions\expect( 'wp_remote_get' )->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
    Functions\expect( 'wp_remote_retrieve_body' )->andReturn( $body );
    Functions\expect( 'set_transient' )->once();

    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-plugin', [ [ 'name' => 'akismet', 'version' => '1.0.0' ] ] );

    expect( $result['findings'] )->toBe( [] );
    expect( $result['snapshots'] )->toHaveCount( 1 );
});

test('vulnerability with no usable advisory ID is excluded from findings', function () {
    $body = (string) json_encode( [
        'akismet' => [
            'vulnerabilities' => [ [
                'references' => [ 'wpvulndb' => [], 'cve' => [] ], // empty both → no advisory id
                'cvss'       => [ 'score' => '7.5' ],
            ] ],
        ],
    ] );

    Functions\expect( 'get_transient' )->andReturn( false );
    Functions\expect( 'wp_remote_get' )->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
    Functions\expect( 'wp_remote_retrieve_body' )->andReturn( $body );
    Functions\expect( 'set_transient' )->once();

    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-plugin', [ [ 'name' => 'akismet', 'version' => '0.9.0' ] ] );

    expect( $result['findings'] )->toBe( [] );
    expect( $result['snapshots'] )->toHaveCount( 1 );
});

test('advisory ID is derived from CVE reference when wpvulndb is absent', function () {
    $body = (string) json_encode( [
        'akismet' => [
            'vulnerabilities' => [ [
                'references' => [ 'wpvulndb' => [], 'cve' => [ '2023-1234' ] ],
                'cvss'       => [ 'score' => '7.5' ],
            ] ],
        ],
    ] );

    Functions\expect( 'get_transient' )->andReturn( false );
    Functions\expect( 'wp_remote_get' )->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
    Functions\expect( 'wp_remote_retrieve_body' )->andReturn( $body );
    Functions\expect( 'set_transient' )->once();

    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-plugin', [ [ 'name' => 'akismet', 'version' => '0.9.0' ] ] );

    expect( $result['findings'] )->toHaveCount( 1 );
    expect( $result['findings'][0]['advisory_id'] )->toBe( 'CVE-2023-1234' );
});

test('severity defaults to medium when vulnerability has no cvss score', function () {
    $body = (string) json_encode( [
        'akismet' => [
            'vulnerabilities' => [ [
                'references' => [ 'wpvulndb' => [ 'vuln-abc' ] ],
                // no cvss field
            ] ],
        ],
    ] );

    Functions\expect( 'get_transient' )->andReturn( false );
    Functions\expect( 'wp_remote_get' )->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
    Functions\expect( 'wp_remote_retrieve_body' )->andReturn( $body );
    Functions\expect( 'set_transient' )->once();

    $result = ( new Kistn_Wpscan_Client( 'tok' ) )->find_advisories( 'wp-plugin', [ [ 'name' => 'akismet', 'version' => '0.9.0' ] ] );

    expect( $result['findings'] )->toHaveCount( 1 );
    expect( $result['findings'][0]['severity'] )->toBe( 'medium' );
});

test('parse_cached_advisories returns empty findings when advisory data is not an array', function () {
    $cached = [ [
        'ecosystem'  => 'wp-plugin',
        'name'       => 'test-plugin',
        'advisories' => null, // non-array → cast_vulnerabilities returns []
        'expires_at' => '2026-06-13T15:00:00+00:00',
    ] ];

    $findings = ( new Kistn_Wpscan_Client( 'tok' ) )->parse_cached_advisories(
        'wp-plugin',
        [ [ 'name' => 'test-plugin', 'version' => '1.0.0' ] ],
        $cached
    );

    expect( $findings )->toBe( [] );
});
