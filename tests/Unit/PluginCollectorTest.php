<?php

use Brain\Monkey\Functions;

test('collect returns all installed plugins with is_active flag', function () {
    Functions\when( 'get_site_transient' )->justReturn( false );
    Functions\expect( 'get_plugins' )->once()->andReturn( [
        'akismet/akismet.php'                  => [ 'Name' => 'Akismet', 'Version' => '5.3.1' ],
        'contact-form-7/wp-contact-form-7.php' => [ 'Name' => 'Contact Form 7', 'Version' => '5.9.0' ],
    ] );
    Functions\expect( 'is_plugin_active' )->andReturnUsing(
        static fn( string $file ): bool => 'akismet/akismet.php' === $file
    );

    $packages = ( new Kistn_Plugin_Collector() )->collect();

    expect( $packages )->toHaveCount( 2 );
    expect( $packages[0] )->toBe( [
        'name'              => 'akismet',
        'version'           => '5.3.1',
        'is_direct'         => true,
        'is_dev'            => false,
        'is_active'         => true,
        'depth'             => 0,
        'source_url'        => null,
        'available_version' => null,
        'author'            => null,
        'in_directory'      => null,
    ] );
    expect( $packages[1]['is_active'] )->toBeFalse();
});

test('collect returns empty array when no plugins installed', function () {
    Functions\when( 'get_site_transient' )->justReturn( false );
    Functions\expect( 'get_plugins' )->andReturn( [] );
    expect( ( new Kistn_Plugin_Collector() )->collect() )->toBe( [] );
});

test('slug is directory name for dir/file.php plugins', function () {
    Functions\when( 'get_site_transient' )->justReturn( false );
    Functions\expect( 'get_plugins' )->andReturn( [
        'woocommerce/woocommerce.php' => [ 'Name' => 'WooCommerce', 'Version' => '8.0.0' ],
    ] );
    Functions\expect( 'is_plugin_active' )->andReturn( true );

    expect( ( new Kistn_Plugin_Collector() )->collect()[0]['name'] )->toBe( 'woocommerce' );
});

test('slug is filename without extension for single-file plugins', function () {
    Functions\when( 'get_site_transient' )->justReturn( false );
    Functions\expect( 'get_plugins' )->andReturn( [
        'hello.php' => [ 'Name' => 'Hello Dolly', 'Version' => '1.7.2' ],
    ] );
    Functions\expect( 'is_plugin_active' )->andReturn( false );

    expect( ( new Kistn_Plugin_Collector() )->collect()[0]['name'] )->toBe( 'hello' );
});

test('in_directory is true when the plugin appears in the update transient', function () {
    $transient            = new stdClass();
    $transient->response  = [];
    $transient->no_update = [ 'akismet/akismet.php' => new stdClass() ];
    $transient->checked   = [ 'akismet/akismet.php' => '5.3.1' ];

    Functions\expect( 'get_site_transient' )->once()->with( 'update_plugins' )->andReturn( $transient );
    Functions\expect( 'get_plugins' )->once()->andReturn( [
        'akismet/akismet.php' => [ 'Name' => 'Akismet', 'Version' => '5.3.1' ],
    ] );
    Functions\expect( 'is_plugin_active' )->andReturn( true );

    expect( ( new Kistn_Plugin_Collector() )->collect()[0]['in_directory'] )->toBeTrue();
});

test('in_directory is false when the plugin was checked but is absent from wordpress.org', function () {
    $transient            = new stdClass();
    $transient->response  = [];
    $transient->no_update = [];
    $transient->checked   = [ 'my-premium-plugin/plugin.php' => '2.0.0' ];

    Functions\expect( 'get_site_transient' )->once()->with( 'update_plugins' )->andReturn( $transient );
    Functions\expect( 'get_plugins' )->once()->andReturn( [
        'my-premium-plugin/plugin.php' => [ 'Name' => 'Premium', 'Version' => '2.0.0' ],
    ] );
    Functions\expect( 'is_plugin_active' )->andReturn( true );

    expect( ( new Kistn_Plugin_Collector() )->collect()[0]['in_directory'] )->toBeFalse();
});

test('in_directory is null when no update check has run', function () {
    Functions\when( 'get_site_transient' )->justReturn( false );
    Functions\expect( 'get_plugins' )->once()->andReturn( [
        'akismet/akismet.php' => [ 'Name' => 'Akismet', 'Version' => '5.3.1' ],
    ] );
    Functions\expect( 'is_plugin_active' )->andReturn( true );

    expect( ( new Kistn_Plugin_Collector() )->collect()[0]['in_directory'] )->toBeNull();
});

test('available_version is populated from update_plugins transient response', function () {
    $transient           = new stdClass();
    $update_entry        = new stdClass();
    $update_entry->new_version = '5.4.0';
    $transient->response = [ 'akismet/akismet.php' => $update_entry ];
    $transient->no_update = [];

    Functions\expect( 'get_site_transient' )->once()->with( 'update_plugins' )->andReturn( $transient );
    Functions\expect( 'get_plugins' )->once()->andReturn( [
        'akismet/akismet.php' => [ 'Name' => 'Akismet', 'Version' => '5.3.1' ],
    ] );
    Functions\expect( 'is_plugin_active' )->andReturn( true );

    $packages = ( new Kistn_Plugin_Collector() )->collect();

    expect( $packages[0]['available_version'] )->toBe( '5.4.0' );
});

test('available_version is populated from update_plugins no_update transient', function () {
    $no_update_entry           = new stdClass();
    $no_update_entry->new_version = '3.2.1';
    $transient                 = new stdClass();
    $transient->response       = [];
    $transient->no_update      = [ 'astra-addon/astra-addon.php' => $no_update_entry ];

    Functions\expect( 'get_site_transient' )->once()->with( 'update_plugins' )->andReturn( $transient );
    Functions\expect( 'get_plugins' )->once()->andReturn( [
        'astra-addon/astra-addon.php' => [ 'Name' => 'Astra Pro', 'Version' => '3.2.1' ],
    ] );
    Functions\expect( 'is_plugin_active' )->andReturn( true );

    $packages = ( new Kistn_Plugin_Collector() )->collect();

    expect( $packages[0]['available_version'] )->toBe( '3.2.1' );
});
