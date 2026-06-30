<?php

use Brain\Monkey\Functions;

test('is_constant_defined returns true for defined constant', function () {
    $helper = new Kistn_Config_Helper();
    expect( $helper->is_constant_defined( 'DAY_IN_SECONDS' ) )->toBeTrue();
});

test('is_constant_defined returns false for undefined constant', function () {
    $helper = new Kistn_Config_Helper();
    expect( $helper->is_constant_defined( 'KISTN_UNDEFINED_CONSTANT_XYZ' ) )->toBeFalse();
});

test('get_constant returns the constant value', function () {
    $helper = new Kistn_Config_Helper();
    expect( $helper->get_constant( 'DAY_IN_SECONDS' ) )->toBe( 86400 );
});

test('get_wp_option delegates to get_option with the given fallback', function () {
    Functions\expect( 'get_option' )
        ->once()
        ->with( 'kistn_base_url', false )
        ->andReturn( 'https://example.com' );

    $helper = new Kistn_Config_Helper();
    expect( $helper->get_wp_option( 'kistn_base_url' ) )->toBe( 'https://example.com' );
});

test('get_wp_option passes custom fallback to get_option', function () {
    Functions\expect( 'get_option' )
        ->once()
        ->with( 'some_option', 'my-default' )
        ->andReturn( 'my-default' );

    $helper = new Kistn_Config_Helper();
    expect( $helper->get_wp_option( 'some_option', 'my-default' ) )->toBe( 'my-default' );
});
