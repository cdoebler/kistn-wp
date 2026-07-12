<?php

use Brain\Monkey\Functions;

test('in_directory reflects wordpress.org theme directory membership', function () {
    $transient            = new stdClass();
    $transient->response  = [];
    $transient->no_update = [ 'twentytwentyfour' => [] ];
    $transient->checked   = [ 'twentytwentyfour' => '1.2.0', 'custom-theme' => '1.0.0' ];

    Functions\expect( 'get_site_transient' )->once()->with( 'update_themes' )->andReturn( $transient );
    Functions\expect( 'wp_get_themes' )->once()->andReturn( [
        'twentytwentyfour' => new WP_Theme( '1.2.0' ),
        'custom-theme'     => new WP_Theme( '1.0.0' ),
    ] );
    Functions\expect( 'get_stylesheet' )->andReturn( 'twentytwentyfour' );
    Functions\expect( 'get_template' )->andReturn( 'twentytwentyfour' );

    $packages = ( new Kistn_Theme_Collector() )->collect();

    expect( $packages[0]['in_directory'] )->toBeTrue()
        ->and( $packages[1]['in_directory'] )->toBeFalse();
});

test('collect returns all themes with is_active flag', function () {
    Functions\when( 'get_site_transient' )->justReturn( false );
    Functions\expect( 'wp_get_themes' )->once()->andReturn( [
        'twentytwentyfour' => new WP_Theme( '1.2.0' ),
        'storefront'       => new WP_Theme( '4.5.0' ),
    ] );
    Functions\expect( 'get_stylesheet' )->andReturn( 'twentytwentyfour' );
    Functions\expect( 'get_template' )->andReturn( 'twentytwentyfour' );

    $packages = ( new Kistn_Theme_Collector() )->collect();

    expect( $packages )->toHaveCount( 2 );
    expect( $packages[0] )->toBe( [
        'name'              => 'twentytwentyfour',
        'version'           => '1.2.0',
        'is_direct'         => true,
        'is_dev'            => false,
        'is_active'         => true,
        'is_child'          => false,
        'depth'             => 0,
        'source_url'        => null,
        'available_version' => null,
        'author'            => null,
        'in_directory'      => null,
    ] );
    expect( $packages[1]['is_active'] )->toBeFalse();
});

test('child theme and parent theme are both active', function () {
    Functions\when( 'get_site_transient' )->justReturn( false );
    Functions\expect( 'wp_get_themes' )->andReturn( [
        'child-theme'  => new WP_Theme( '1.0.0' ),
        'parent-theme' => new WP_Theme( '2.0.0' ),
        'other-theme'  => new WP_Theme( '3.0.0' ),
    ] );
    Functions\expect( 'get_stylesheet' )->andReturn( 'child-theme' );
    Functions\expect( 'get_template' )->andReturn( 'parent-theme' );

    $packages = ( new Kistn_Theme_Collector() )->collect();
    $by_name  = array_column( $packages, null, 'name' );

    expect( $by_name['child-theme']['is_active'] )->toBeTrue();
    expect( $by_name['parent-theme']['is_active'] )->toBeTrue();
    expect( $by_name['other-theme']['is_active'] )->toBeFalse();
});

test('child theme is marked with is_child true', function () {
    Functions\when( 'get_site_transient' )->justReturn( false );
    // Real WP_Theme::get('Template') returns the theme's own slug when it has no
    // parent, so the parent stub passes its own slug — not an empty string.
    Functions\expect( 'wp_get_themes' )->andReturn( [
        'child-theme'  => new WP_Theme( '1.0.0', 'parent-theme' ),
        'parent-theme' => new WP_Theme( '2.0.0', 'parent-theme' ),
    ] );
    Functions\expect( 'get_stylesheet' )->andReturn( 'child-theme' );
    Functions\expect( 'get_template' )->andReturn( 'parent-theme' );

    $packages = ( new Kistn_Theme_Collector() )->collect();
    $by_name  = array_column( $packages, null, 'name' );

    expect( $by_name['child-theme']['is_child'] )->toBeTrue();
    expect( $by_name['parent-theme']['is_child'] )->toBeFalse();
});

test('available_version is populated from update_themes transient response', function () {
    $transient           = new stdClass();
    $transient->response = [ 'storefront' => [ 'new_version' => '4.6.0' ] ];
    $transient->no_update = [];

    Functions\expect( 'get_site_transient' )->once()->with( 'update_themes' )->andReturn( $transient );
    Functions\expect( 'wp_get_themes' )->once()->andReturn( [
        'storefront' => new WP_Theme( '4.5.0' ),
    ] );
    Functions\expect( 'get_stylesheet' )->andReturn( 'storefront' );
    Functions\expect( 'get_template' )->andReturn( 'storefront' );

    $packages = ( new Kistn_Theme_Collector() )->collect();

    expect( $packages[0]['available_version'] )->toBe( '4.6.0' );
});
