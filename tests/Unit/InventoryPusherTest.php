<?php

use Brain\Monkey\Functions;

function make_pusher_mocks(): array {
    return [
        'http'    => Mockery::mock( Kistn_Http_Client::class ),
        'plugins' => Mockery::mock( Kistn_Plugin_Collector::class ),
        'themes'  => Mockery::mock( Kistn_Theme_Collector::class ),
        'wpscan'  => Mockery::mock( Kistn_Wpscan_Client::class ),
        'core'    => Mockery::mock( Kistn_Core_Collector::class ),
    ];
}

/** @param array{http: Kistn_Http_Client, plugins: Kistn_Plugin_Collector, themes: Kistn_Theme_Collector, wpscan: Kistn_Wpscan_Client, core: Kistn_Core_Collector} $m */
function make_pusher( array $m ): Kistn_Inventory_Pusher {
    return new Kistn_Inventory_Pusher( $m['http'], $m['plugins'], $m['themes'], $m['wpscan'], $m['core'] );
}

function core_package(): array {
    return [ 'name' => 'wordpress', 'version' => '6.5.3', 'is_direct' => true, 'is_dev' => false, 'is_active' => true, 'depth' => 0 ];
}

test('push skips wp-plugin when content hash matches server hash', function () {
    $plugin_packages = [ [ 'name' => 'akismet', 'version' => '5.3.1', 'is_direct' => true, 'is_dev' => false, 'is_active' => true, 'depth' => 0 ] ];
    $plugin_hash     = hash( 'sha256', (string) json_encode( $plugin_packages ) );

    $m = make_pusher_mocks();
    $m['core']->allows( 'collect' )->andReturn( [ core_package() ] );
    $m['http']->allows( 'get_hashes' )->andReturn( [ 'wp-core' => null, 'wp-plugin' => $plugin_hash, 'wp-theme' => null ] );
    $m['plugins']->allows( 'collect' )->andReturn( $plugin_packages );
    $m['themes']->allows( 'collect' )->andReturn( [] );
    $m['wpscan']->allows( 'find_advisories' )->andReturn( [ 'findings' => [], 'snapshots' => [], 'not_found' => [] ] );
    $m['wpscan']->allows( 'parse_cached_advisories' )->andReturn( [] );
    $m['http']->allows( 'preflight' )->andReturn( [ 'stale' => [], 'advisories' => [], 'private' => [] ] );

    // push must be called without wp-plugin in the map
    $m['http']->expects( 'push' )
        ->once()
        ->with( Mockery::on( static fn( array $e ): bool => ! array_key_exists( 'wp-plugin', $e ) ) );

    Functions\expect( 'wp_json_encode' )->andReturnUsing( static fn( mixed $d ): string|false => json_encode( $d ) );
    Functions\expect( 'set_transient' )->zeroOrMoreTimes();

    make_pusher( $m )->push();

    expect( true )->toBeTrue();
});

test('push sends wp-plugin payload when content hash differs from server', function () {
    $plugin_packages = [ [ 'name' => 'akismet', 'version' => '5.3.1', 'is_direct' => true, 'is_dev' => false, 'is_active' => true, 'depth' => 0, 'source_url' => 'https://wordpress.org/plugins/akismet' ] ];

    $m = make_pusher_mocks();
    $m['core']->allows( 'collect' )->andReturn( [ core_package() ] );
    $m['http']->allows( 'get_hashes' )->andReturn( [ 'wp-core' => null, 'wp-plugin' => 'old-hash', 'wp-theme' => null ] );
    $m['plugins']->allows( 'collect' )->andReturn( $plugin_packages );
    $m['themes']->allows( 'collect' )->andReturn( [] );
    $m['wpscan']->allows( 'find_advisories' )->andReturn( [ 'findings' => [], 'snapshots' => [], 'not_found' => [] ] );
    $m['wpscan']->allows( 'parse_cached_advisories' )->andReturn( [] );
    $m['http']->allows( 'preflight' )->andReturn( [ 'stale' => [], 'advisories' => [], 'private' => [] ] );

    $m['http']->expects( 'push' )
        ->once()
        ->with( Mockery::on( static fn( array $e ): bool =>
            isset( $e['wp-plugin'] ) && $e['wp-plugin']['packages'] === $plugin_packages
        ) );

    Functions\expect( 'wp_json_encode' )->andReturnUsing( static fn( mixed $d ): string|false => json_encode( $d ) );
    Functions\expect( 'set_transient' )->zeroOrMoreTimes();

    make_pusher( $m )->push();

    expect( true )->toBeTrue();
});

test('push runs for all three ecosystems in one call', function () {
    $plugins = [ [ 'name' => 'akismet', 'version' => '5.3.1', 'is_direct' => true, 'is_dev' => false, 'is_active' => true, 'depth' => 0, 'source_url' => 'https://wordpress.org/plugins/akismet' ] ];
    $themes  = [ [ 'name' => 'twentytwentyfour', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false, 'is_active' => true, 'depth' => 0, 'source_url' => 'https://wordpress.org/themes/twentytwentyfour' ] ];

    $m = make_pusher_mocks();
    $m['core']->allows( 'collect' )->andReturn( [ core_package() ] );
    $m['http']->allows( 'get_hashes' )->andReturn( [ 'wp-core' => 'old-hash', 'wp-plugin' => 'old-hash', 'wp-theme' => 'old-hash' ] );
    $m['plugins']->allows( 'collect' )->andReturn( $plugins );
    $m['themes']->allows( 'collect' )->andReturn( $themes );
    $m['wpscan']->allows( 'find_advisories' )->andReturn( [ 'findings' => [], 'snapshots' => [], 'not_found' => [] ] );
    $m['wpscan']->allows( 'parse_cached_advisories' )->andReturn( [] );
    $m['http']->allows( 'preflight' )->andReturn( [ 'stale' => [], 'advisories' => [], 'private' => [] ] );

    $m['http']->expects( 'push' )
        ->once()
        ->with( Mockery::on( static fn( array $e ): bool =>
            isset( $e['wp-core'], $e['wp-plugin'], $e['wp-theme'] )
        ) );

    Functions\expect( 'wp_json_encode' )->andReturnUsing( static fn( mixed $d ): string|false => json_encode( $d ) );
    Functions\expect( 'set_transient' )->zeroOrMoreTimes();

    make_pusher( $m )->push();

    expect( true )->toBeTrue();
});

test('push calls preflight once with all packages before building payload', function () {
    $plugins = [ [ 'name' => 'akismet', 'version' => '5.3.1', 'is_direct' => true, 'is_dev' => false, 'is_active' => true, 'depth' => 0, 'source_url' => 'https://wordpress.org/plugins/akismet' ] ];
    $themes  = [ [ 'name' => 'twentyfour', 'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false, 'is_active' => true, 'depth' => 0, 'source_url' => 'https://wordpress.org/themes/twentyfour' ] ];

    $m = make_pusher_mocks();
    $m['core']->allows( 'collect' )->andReturn( [ core_package() ] );
    $m['plugins']->allows( 'collect' )->andReturn( $plugins );
    $m['themes']->allows( 'collect' )->andReturn( $themes );
    $m['http']->allows( 'get_hashes' )->andReturn( [ 'wp-core' => 'old-hash', 'wp-plugin' => 'old-hash', 'wp-theme' => 'old-hash' ] );
    $m['wpscan']->allows( 'find_advisories' )->andReturn( [ 'findings' => [], 'snapshots' => [], 'not_found' => [] ] );
    $m['wpscan']->allows( 'parse_cached_advisories' )->andReturn( [] );
    $m['http']->allows( 'push' );

    $expected_packages = [
        [ 'ecosystem' => 'wp-core',   'name' => 'wordpress',   'version' => '6.5.3' ],
        [ 'ecosystem' => 'wp-plugin', 'name' => 'akismet',     'version' => '5.3.1' ],
        [ 'ecosystem' => 'wp-theme',  'name' => 'twentyfour',  'version' => '1.0.0' ],
    ];

    $m['http']->expects( 'preflight' )
        ->once()
        ->with( $expected_packages )
        ->andReturn( [ 'stale' => [], 'advisories' => [], 'private' => [] ] );

    Functions\expect( 'wp_json_encode' )->andReturnUsing( static fn( mixed $d ): string|false => json_encode( $d ) );
    Functions\expect( 'set_transient' )->zeroOrMoreTimes();

    make_pusher( $m )->push();

    expect( true )->toBeTrue();
});

test('push queries WPScan only for slugs marked stale by preflight', function () {
    $plugins = [
        [ 'name' => 'akismet',     'version' => '5.3.1', 'is_direct' => true, 'is_dev' => false, 'is_active' => true, 'depth' => 0, 'source_url' => 'https://wordpress.org/plugins/akismet' ],
        [ 'name' => 'woocommerce', 'version' => '8.0.0', 'is_direct' => true, 'is_dev' => false, 'is_active' => true, 'depth' => 0, 'source_url' => 'https://wordpress.org/plugins/woocommerce' ],
    ];

    $m = make_pusher_mocks();
    $m['core']->allows( 'collect' )->andReturn( [] );
    $m['plugins']->allows( 'collect' )->andReturn( $plugins );
    $m['themes']->allows( 'collect' )->andReturn( [] );
    $m['http']->allows( 'get_hashes' )->andReturn( [ 'wp-core' => null, 'wp-plugin' => 'old-hash', 'wp-theme' => null ] );
    $m['http']->allows( 'push' );
    $m['wpscan']->allows( 'parse_cached_advisories' )->andReturn( [] );

    $m['http']->allows( 'preflight' )->andReturn( [
        'stale'      => [ [ 'ecosystem' => 'wp-plugin', 'name' => 'akismet' ] ],
        'advisories' => [ [ 'ecosystem' => 'wp-plugin', 'name' => 'woocommerce', 'advisories' => [], 'expires_at' => '2026-06-13T15:00:00Z' ] ],
        'private'    => [],
    ] );

    $m['wpscan']->expects( 'find_advisories' )
        ->once()
        ->with( 'wp-plugin', [ [ 'name' => 'akismet', 'version' => '5.3.1' ] ] )
        ->andReturn( [ 'findings' => [], 'snapshots' => [], 'not_found' => [] ] );

    Functions\expect( 'wp_json_encode' )->andReturnUsing( static fn( mixed $d ): string|false => json_encode( $d ) );
    Functions\expect( 'set_transient' )->zeroOrMoreTimes();

    make_pusher( $m )->push();

    expect( true )->toBeTrue();
});

test('push infers private for packages without source_url without querying WPScan', function () {
    $plugins = [
        [ 'name' => 'public-plugin',  'version' => '1.0.0', 'is_direct' => true, 'is_dev' => false, 'is_active' => true, 'depth' => 0, 'source_url' => 'https://wordpress.org/plugins/public-plugin' ],
        [ 'name' => 'private-plugin', 'version' => '2.0.0', 'is_direct' => true, 'is_dev' => false, 'is_active' => true, 'depth' => 0, 'source_url' => null ],
    ];

    $m = make_pusher_mocks();
    $m['core']->allows( 'collect' )->andReturn( [] );
    $m['plugins']->allows( 'collect' )->andReturn( $plugins );
    $m['themes']->allows( 'collect' )->andReturn( [] );
    $m['http']->allows( 'get_hashes' )->andReturn( [ 'wp-core' => null, 'wp-plugin' => 'old-hash', 'wp-theme' => null ] );
    $m['wpscan']->allows( 'parse_cached_advisories' )->andReturn( [] );

    $m['http']->allows( 'preflight' )->andReturn( [
        'stale'      => [
            [ 'ecosystem' => 'wp-plugin', 'name' => 'public-plugin' ],
            [ 'ecosystem' => 'wp-plugin', 'name' => 'private-plugin' ],
        ],
        'advisories' => [],
        'private'    => [],
    ] );

    $m['wpscan']->expects( 'find_advisories' )
        ->once()
        ->with( 'wp-plugin', [ [ 'name' => 'public-plugin', 'version' => '1.0.0' ] ] )
        ->andReturn( [ 'findings' => [], 'snapshots' => [], 'not_found' => [] ] );

    $m['http']->expects( 'push' )
        ->once()
        ->with( Mockery::on( static fn( array $e ): bool =>
            isset( $e['wp-plugin'] )
            && in_array( 'private-plugin', $e['wp-plugin']['private_packages'], true )
        ) );

    Functions\expect( 'wp_json_encode' )->andReturnUsing( static fn( mixed $d ): string|false => json_encode( $d ) );
    Functions\expect( 'set_transient' )->zeroOrMoreTimes();

    make_pusher( $m )->push();

    expect( true )->toBeTrue();
});

test('push passes advisory snapshots in ecosystem payload', function () {
    $plugins   = [ [ 'name' => 'akismet', 'version' => '5.3.1', 'is_direct' => true, 'is_dev' => false, 'is_active' => true, 'depth' => 0, 'source_url' => 'https://wordpress.org/plugins/akismet' ] ];
    $snapshots = [ [ 'ecosystem' => 'wp-plugin', 'name' => 'akismet', 'payload' => [] ] ];

    $m = make_pusher_mocks();
    $m['core']->allows( 'collect' )->andReturn( [] );
    $m['plugins']->allows( 'collect' )->andReturn( $plugins );
    $m['themes']->allows( 'collect' )->andReturn( [] );
    $m['http']->allows( 'get_hashes' )->andReturn( [ 'wp-core' => null, 'wp-plugin' => 'old-hash', 'wp-theme' => null ] );
    $m['wpscan']->allows( 'parse_cached_advisories' )->andReturn( [] );
    $m['http']->allows( 'preflight' )->andReturn( [
        'stale'      => [ [ 'ecosystem' => 'wp-plugin', 'name' => 'akismet' ] ],
        'advisories' => [],
        'private'    => [],
    ] );
    $m['wpscan']->allows( 'find_advisories' )->andReturn( [ 'findings' => [], 'snapshots' => $snapshots, 'not_found' => [] ] );

    $m['http']->expects( 'push' )
        ->once()
        ->with( Mockery::on( static fn( array $e ): bool =>
            isset( $e['wp-plugin'] )
            && $e['wp-plugin']['advisories'] === $snapshots
        ) );

    Functions\expect( 'wp_json_encode' )->andReturnUsing( static fn( mixed $d ): string|false => json_encode( $d ) );
    Functions\expect( 'set_transient' )->zeroOrMoreTimes();

    make_pusher( $m )->push();

    expect( true )->toBeTrue();
});

test('push makes no api request when all ecosystem hashes already match server', function () {
    $core_pkgs   = [ core_package() ];
    $plugin_pkgs = [ [ 'name' => 'akismet', 'version' => '5.3.1', 'is_direct' => true, 'is_dev' => false, 'is_active' => true, 'depth' => 0 ] ];

    Functions\expect( 'wp_json_encode' )->andReturnUsing( static fn( mixed $d ): string|false => json_encode( $d ) );

    $core_hash   = hash( 'sha256', (string) json_encode( $core_pkgs ) );
    $plugin_hash = hash( 'sha256', (string) json_encode( $plugin_pkgs ) );

    $m = make_pusher_mocks();
    $m['core']->allows( 'collect' )->andReturn( $core_pkgs );
    $m['plugins']->allows( 'collect' )->andReturn( $plugin_pkgs );
    $m['themes']->allows( 'collect' )->andReturn( [] );
    $m['http']->allows( 'get_hashes' )->andReturn( [
        'wp-core'   => $core_hash,
        'wp-plugin' => $plugin_hash,
        'wp-theme'  => null,
    ] );
    $m['http']->allows( 'preflight' )->andReturn( [ 'stale' => [], 'advisories' => [], 'private' => [] ] );
    $m['http']->expects( 'push' )->never();

    make_pusher( $m )->push();

    expect( true )->toBeTrue();
});
