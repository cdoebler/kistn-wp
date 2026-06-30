<?php

use Brain\Monkey\Functions;

test('collect returns wordpress core as a package', function () {
    Functions\when( 'get_site_transient' )->justReturn( false );
    Functions\expect( 'get_bloginfo' )->once()->with( 'version' )->andReturn( '6.5.3' );

    $packages = ( new Kistn_Core_Collector() )->collect();

    expect( $packages )->toHaveCount( 1 );
    expect( $packages[0] )->toBe( [
        'name'              => 'wordpress',
        'version'           => '6.5.3',
        'is_direct'         => true,
        'is_dev'            => false,
        'is_active'         => true,
        'depth'             => 0,
        'source_url'        => null,
        'available_version' => null,
    ] );
});

test('available_version is populated from update_core transient', function () {
    $update           = new stdClass();
    $update->response = 'upgrade';
    $update->current  = '6.6.0';

    $update_core          = new stdClass();
    $update_core->updates = [ $update ];

    Functions\expect( 'get_site_transient' )->once()->with( 'update_core' )->andReturn( $update_core );
    Functions\expect( 'get_bloginfo' )->once()->with( 'version' )->andReturn( '6.5.3' );

    $packages = ( new Kistn_Core_Collector() )->collect();

    expect( $packages[0]['available_version'] )->toBe( '6.6.0' );
});

test('available_version skips non-object entries in updates array', function () {
    $valid_update           = new stdClass();
    $valid_update->response = 'upgrade';
    $valid_update->current  = '6.6.0';

    $update_core          = new stdClass();
    $update_core->updates = [ 'not-an-object', $valid_update ];

    Functions\expect( 'get_site_transient' )->once()->with( 'update_core' )->andReturn( $update_core );
    Functions\expect( 'get_bloginfo' )->once()->with( 'version' )->andReturn( '6.5.3' );

    $packages = ( new Kistn_Core_Collector() )->collect();

    expect( $packages[0]['available_version'] )->toBe( '6.6.0' );
});

test('available_version skips development releases in update_core transient', function () {
    $dev_update           = new stdClass();
    $dev_update->response = 'development';
    $dev_update->current  = '6.6-beta1';

    $stable_update           = new stdClass();
    $stable_update->response = 'upgrade';
    $stable_update->current  = '6.5.4';

    $update_core          = new stdClass();
    $update_core->updates = [ $dev_update, $stable_update ];

    Functions\expect( 'get_site_transient' )->once()->with( 'update_core' )->andReturn( $update_core );
    Functions\expect( 'get_bloginfo' )->once()->with( 'version' )->andReturn( '6.5.3' );

    $packages = ( new Kistn_Core_Collector() )->collect();

    expect( $packages[0]['available_version'] )->toBe( '6.5.4' );
});
