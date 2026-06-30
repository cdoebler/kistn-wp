<?php

use Brain\Monkey\Functions;

function make_http_client(): Kistn_Http_Client {
    $config = Mockery::mock( Kistn_Config::class );
    $config->allows( 'base_url' )->andReturn( 'https://api.example.com' );
    $config->allows( 'project_id' )->andReturn( 'uuid-abc' );
    $config->allows( 'token' )->andReturn( 'tok-secret' );

    return new Kistn_Http_Client( $config );
}

test('get_hashes returns all ecosystem hashes from 200 response', function () {
    $body = json_encode( [ 'wp-plugin' => 'abc123', 'wp-theme' => null, 'wp-core' => null ] );

    Functions\expect( 'wp_remote_get' )
        ->once()
        ->with(
            'https://api.example.com/api/projects/uuid-abc/hashes',
            Mockery::subset( [ 'headers' => [ 'Authorization' => 'Bearer tok-secret' ] ] )
        )
        ->andReturn( [] );

    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
    Functions\expect( 'wp_remote_retrieve_body' )->andReturn( $body );

    $hashes = make_http_client()->get_hashes();

    expect( $hashes )->toBeArray()
        ->and( $hashes['wp-plugin'] )->toBe( 'abc123' )
        ->and( $hashes['wp-theme'] )->toBeNull();
});

test('get_hashes returns empty array on WP_Error', function () {
    Functions\expect( 'wp_remote_get' )->andReturn( new WP_Error() );
    Functions\expect( 'is_wp_error' )->andReturn( true );
    Functions\expect( 'error_log' )->once();

    expect( make_http_client()->get_hashes() )->toBe( [] );
});

test('get_hashes returns empty array on non-200 response', function () {
    Functions\expect( 'wp_remote_get' )->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 404 );

    expect( make_http_client()->get_hashes() )->toBe( [] );
});

test('push sends ecosystems as JSON body to project-level inventory endpoint', function () {
    $packages = [ [ 'name' => 'akismet', 'version' => '5.3.1', 'is_direct' => true, 'is_dev' => false, 'is_active' => true, 'depth' => 0 ] ];

    Functions\expect( 'wp_remote_post' )
        ->once()
        ->with(
            'https://api.example.com/api/projects/uuid-abc/inventory',
            Mockery::on( static function ( array $args ) use ( $packages ): bool {
                $body = json_decode( $args['body'], true );
                return isset( $body['ecosystems']['wp-plugin']['packages'] )
                    && $body['ecosystems']['wp-plugin']['packages'] === $packages;
            } )
        )
        ->andReturn( [] );

    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 202 );
    Functions\expect( 'wp_json_encode' )->andReturnUsing(
        static fn( mixed $data ): string|false => json_encode( $data )
    );

    make_http_client()->push( [
        'wp-plugin' => [
            'packages'         => $packages,
            'findings'         => [],
            'advisories'       => [],
            'private_packages' => [],
        ],
    ] );

    expect( true )->toBeTrue();
});

test('push logs error and stores kistn_last_error on WP_Error', function () {
    Functions\expect( 'wp_remote_post' )->andReturn( new WP_Error() );
    Functions\expect( 'is_wp_error' )->andReturn( true );
    Functions\expect( 'wp_json_encode' )->andReturnUsing(
        static fn( mixed $data ): string|false => json_encode( $data )
    );
    Functions\expect( 'error_log' )->once();
    Functions\expect( 'update_option' )->once()->with( 'kistn_last_error', Mockery::type( 'string' ) );

    make_http_client()->push( [
        'wp-plugin' => [ 'packages' => [], 'findings' => [], 'advisories' => [], 'private_packages' => [] ],
    ] );

    expect( true )->toBeTrue();
});

test('push bundles multiple ecosystems in one request', function () {
    Functions\expect( 'wp_remote_post' )
        ->once()
        ->with(
            Mockery::any(),
            Mockery::on( static function ( array $args ): bool {
                $body = json_decode( $args['body'], true );
                return isset( $body['ecosystems']['wp-plugin'], $body['ecosystems']['wp-theme'] );
            } )
        )
        ->andReturn( [] );

    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 202 );
    Functions\expect( 'wp_json_encode' )->andReturnUsing(
        static fn( mixed $data ): string|false => json_encode( $data )
    );

    make_http_client()->push( [
        'wp-plugin' => [ 'packages' => [], 'findings' => [], 'advisories' => [], 'private_packages' => [] ],
        'wp-theme'  => [ 'packages' => [], 'findings' => [], 'advisories' => [], 'private_packages' => [] ],
    ] );

    expect( true )->toBeTrue();
});

test('push includes advisories field when advisory snapshots provided', function () {
    $config = Mockery::mock( Kistn_Config::class );
    $config->allows( 'base_url' )->andReturn( 'https://example.com' );
    $config->allows( 'project_id' )->andReturn( 'proj-uuid' );
    $config->allows( 'token' )->andReturn( 'tok' );

    $snapshots = [ [ 'ecosystem' => 'wp-plugin', 'name' => 'akismet', 'payload' => [] ] ];

    Functions\expect( 'wp_remote_post' )
        ->once()
        ->with(
            Mockery::any(),
            Mockery::on( static function ( array $args ) use ( $snapshots ): bool {
                $body = json_decode( $args['body'], true );
                return isset( $body['ecosystems']['wp-plugin']['advisories'] )
                    && $body['ecosystems']['wp-plugin']['advisories'] === $snapshots;
            } )
        )
        ->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 202 );
    Functions\expect( 'wp_json_encode' )->andReturnUsing( static fn( mixed $d ): string|false => json_encode( $d ) );

    ( new Kistn_Http_Client( $config ) )->push( [
        'wp-plugin' => [
            'packages'         => [],
            'findings'         => [],
            'advisories'       => $snapshots,
            'private_packages' => [],
        ],
    ] );

    expect( true )->toBeTrue();
});

test('preflight returns stale and advisories from server response', function () {
    $config = Mockery::mock( Kistn_Config::class );
    $config->allows( 'base_url' )->andReturn( 'https://example.com' );
    $config->allows( 'project_id' )->andReturn( 'proj-uuid' );
    $config->allows( 'token' )->andReturn( 'tok' );

    $server_response = json_encode( [
        'stale'      => [ [ 'ecosystem' => 'wp-plugin', 'name' => 'bad-plugin' ] ],
        'advisories' => [ [ 'ecosystem' => 'wp-plugin', 'name' => 'woocommerce', 'advisories' => [], 'expires_at' => '2026-06-13T15:00:00Z' ] ],
    ] );

    Functions\expect( 'wp_remote_post' )
        ->once()
        ->with( 'https://example.com/api/projects/proj-uuid/preflight/wp', Mockery::any() )
        ->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
    Functions\expect( 'wp_remote_retrieve_body' )->andReturn( $server_response );
    Functions\expect( 'wp_json_encode' )->andReturnUsing( static fn( mixed $d ): string|false => json_encode( $d ) );

    $result = ( new Kistn_Http_Client( $config ) )->preflight( [
        [ 'ecosystem' => 'wp-plugin', 'name' => 'bad-plugin',  'version' => '1.0.0' ],
        [ 'ecosystem' => 'wp-plugin', 'name' => 'woocommerce', 'version' => '8.0.0' ],
    ] );

    expect( $result['stale'] )->toHaveCount( 1 );
    expect( $result['stale'][0]['name'] )->toBe( 'bad-plugin' );
    expect( $result['advisories'] )->toHaveCount( 1 );
    expect( $result['advisories'][0]['name'] )->toBe( 'woocommerce' );
});

test('preflight falls back to all-stale on HTTP error', function () {
    $config = Mockery::mock( Kistn_Config::class );
    $config->allows( 'base_url' )->andReturn( 'https://example.com' );
    $config->allows( 'project_id' )->andReturn( 'proj-uuid' );
    $config->allows( 'token' )->andReturn( 'tok' );

    $error = Mockery::mock( \WP_Error::class );
    $error->allows( 'get_error_message' )->andReturn( 'connection refused' );

    Functions\expect( 'wp_remote_post' )->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( true );
    Functions\expect( 'wp_json_encode' )->andReturnUsing( static fn( mixed $d ): string|false => json_encode( $d ) );

    $packages = [
        [ 'ecosystem' => 'wp-plugin', 'name' => 'akismet', 'version' => '5.3.1' ],
    ];

    $result = ( new Kistn_Http_Client( $config ) )->preflight( $packages );

    expect( $result['stale'] )->toHaveCount( 1 );
    expect( $result['stale'][0]['name'] )->toBe( 'akismet' );
    expect( $result['advisories'] )->toBe( [] );
});

test('get_hashes returns empty array when response body is not a JSON array', function () {
    Functions\expect( 'wp_remote_get' )->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
    Functions\expect( 'wp_remote_retrieve_body' )->andReturn( '"not-an-array"' );

    expect( make_http_client()->get_hashes() )->toBe( [] );
});

test('preflight returns all-stale when response body is not a JSON array', function () {
    $config = Mockery::mock( Kistn_Config::class );
    $config->allows( 'base_url' )->andReturn( 'https://example.com' );
    $config->allows( 'project_id' )->andReturn( 'proj-uuid' );
    $config->allows( 'token' )->andReturn( 'tok' );

    Functions\expect( 'wp_remote_post' )->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
    Functions\expect( 'wp_remote_retrieve_body' )->andReturn( '"not-an-object"' );
    Functions\expect( 'wp_json_encode' )->andReturnUsing( static fn( mixed $d ): string|false => json_encode( $d ) );

    $result = ( new Kistn_Http_Client( $config ) )->preflight( [
        [ 'ecosystem' => 'wp-plugin', 'name' => 'akismet', 'version' => '5.3.1' ],
    ] );

    expect( $result['stale'] )->toHaveCount( 1 );
    expect( $result['advisories'] )->toBe( [] );
    expect( $result['private'] )->toBe( [] );
});

test('preflight skips malformed advisory items that are not arrays', function () {
    $config = Mockery::mock( Kistn_Config::class );
    $config->allows( 'base_url' )->andReturn( 'https://example.com' );
    $config->allows( 'project_id' )->andReturn( 'proj-uuid' );
    $config->allows( 'token' )->andReturn( 'tok' );

    $server_response = (string) json_encode( [
        'stale'      => [],
        'advisories' => [ 'not-an-array-item' ],
    ] );

    Functions\expect( 'wp_remote_post' )->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
    Functions\expect( 'wp_remote_retrieve_body' )->andReturn( $server_response );
    Functions\expect( 'wp_json_encode' )->andReturnUsing( static fn( mixed $d ): string|false => json_encode( $d ) );

    $result = ( new Kistn_Http_Client( $config ) )->preflight( [
        [ 'ecosystem' => 'wp-plugin', 'name' => 'akismet', 'version' => '5.3.1' ],
    ] );

    expect( $result['advisories'] )->toBe( [] );
});

test('preflight returns server-confirmed private packages', function () {
    $config = Mockery::mock( Kistn_Config::class );
    $config->allows( 'base_url' )->andReturn( 'https://example.com' );
    $config->allows( 'project_id' )->andReturn( 'proj-uuid' );
    $config->allows( 'token' )->andReturn( 'tok' );

    $server_response = (string) json_encode( [
        'stale'      => [],
        'advisories' => [],
        'private'    => [ 'private-plugin', 'another-private' ],
    ] );

    Functions\expect( 'wp_remote_post' )->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 200 );
    Functions\expect( 'wp_remote_retrieve_body' )->andReturn( $server_response );
    Functions\expect( 'wp_json_encode' )->andReturnUsing( static fn( mixed $d ): string|false => json_encode( $d ) );

    $result = ( new Kistn_Http_Client( $config ) )->preflight( [
        [ 'ecosystem' => 'wp-plugin', 'name' => 'private-plugin', 'version' => '1.0.0' ],
    ] );

    expect( $result['private'] )->toBe( [ 'private-plugin', 'another-private' ] );
});

test('push logs error and stores kistn_last_error when server returns unexpected status', function () {
    Functions\expect( 'wp_remote_post' )->andReturn( [] );
    Functions\expect( 'is_wp_error' )->andReturn( false );
    Functions\expect( 'wp_remote_retrieve_response_code' )->andReturn( 422 );
    Functions\expect( 'wp_remote_retrieve_body' )->andReturn( '{"error":"unprocessable"}' );
    Functions\expect( 'wp_json_encode' )->andReturnUsing( static fn( mixed $d ): string|false => json_encode( $d ) );
    Functions\expect( 'error_log' )->once();
    Functions\expect( 'update_option' )->once()->with( 'kistn_last_error', Mockery::type( 'string' ) );

    make_http_client()->push( [
        'wp-plugin' => [ 'packages' => [], 'findings' => [], 'advisories' => [], 'private_packages' => [] ],
    ] );

    expect( true )->toBeTrue();
});
