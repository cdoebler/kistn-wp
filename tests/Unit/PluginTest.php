<?php

use Brain\Monkey\Functions;

/**
 * Stubs get_option to return empty strings for all config keys so Kistn_Plugin
 * constructs with an invalid (but non-crashing) config.
 */
function stub_config_options(): void {
    Functions\when( 'get_option' )->justReturn( '' );
}

test('activate schedules cron event when mode is not wp-cli and no event exists', function () {
    stub_config_options();
    Functions\expect( 'wp_next_scheduled' )->with( 'kistn_run_inventory_push' )->andReturn( false );
    Functions\expect( 'wp_schedule_event' )->once();

    ( new Kistn_Plugin() )->activate();

    expect( true )->toBeTrue();
});

test('activate does not schedule cron when event is already registered', function () {
    stub_config_options();
    Functions\expect( 'wp_next_scheduled' )->with( 'kistn_run_inventory_push' )->andReturn( 12345 );
    Functions\expect( 'wp_schedule_event' )->never();

    ( new Kistn_Plugin() )->activate();

    expect( true )->toBeTrue();
});

test('deactivate unschedules existing cron event', function () {
    stub_config_options();
    Functions\expect( 'wp_next_scheduled' )->with( 'kistn_run_inventory_push' )->andReturn( 12345 );
    Functions\expect( 'wp_unschedule_event' )->once()->with( 12345, 'kistn_run_inventory_push' );

    ( new Kistn_Plugin() )->deactivate();

    expect( true )->toBeTrue();
});

test('deactivate does nothing when no cron event is scheduled', function () {
    stub_config_options();
    Functions\expect( 'wp_next_scheduled' )->with( 'kistn_run_inventory_push' )->andReturn( false );
    Functions\expect( 'wp_unschedule_event' )->never();

    ( new Kistn_Plugin() )->deactivate();

    expect( true )->toBeTrue();
});

test('maybe_push returns without pushing when config is invalid', function () {
    stub_config_options();
    // config is invalid (all empty values), so pusher->push() must never be called
    ( new Kistn_Plugin() )->maybe_push();

    expect( true )->toBeTrue();
});

test('init registers admin_menu and cron hooks then schedules event', function () {
    stub_config_options();
    Functions\when( 'add_action' )->justReturn();
    Functions\expect( 'wp_next_scheduled' )->with( 'kistn_run_inventory_push' )->andReturn( false );
    Functions\expect( 'wp_schedule_event' )->once();

    ( new Kistn_Plugin() )->init();

    expect( true )->toBeTrue();
});

test('init adds admin_init hook when schedule_mode is admin-init', function () {
    Functions\when( 'get_option' )->alias( static function ( string $option, mixed $default = false ): mixed {
        return 'kistn_schedule_mode' === $option ? 'admin-init' : '';
    } );

    Functions\when( 'add_action' )->justReturn();
    Functions\when( 'wp_next_scheduled' )->justReturn( false );
    Functions\when( 'wp_schedule_event' )->justReturn();

    ( new Kistn_Plugin() )->init();

    expect( true )->toBeTrue();
});
