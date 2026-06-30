<?php

use Brain\Monkey\Functions;

function stub_wp_rendering(): void {
    Functions\when( 'esc_html_e' )->justReturn();
    Functions\when( 'esc_attr_e' )->justReturn();
    Functions\when( 'esc_html' )->returnArg();
    Functions\when( 'esc_attr' )->returnArg();
    Functions\when( 'selected' )->justReturn( '' );
    Functions\when( 'wp_nonce_field' )->justReturn( '' );
    Functions\when( '__' )->returnArg();
}

test('register adds settings page to the WP admin menu', function () {
    Functions\when( '__' )->returnArg();
    Functions\expect( 'add_options_page' )
        ->once()
        ->with( Mockery::any(), Mockery::any(), 'manage_options', 'kistn', Mockery::any() );

    $config = Mockery::mock( Kistn_Config::class );
    ( new Kistn_Settings_Page( $config ) )->register();

    expect( true )->toBeTrue();
});

test('render returns without output when user lacks manage_options capability', function () {
    Functions\expect( 'current_user_can' )->with( 'manage_options' )->andReturn( false );

    $config = Mockery::mock( Kistn_Config::class );

    ob_start();
    ( new Kistn_Settings_Page( $config ) )->render();
    $output = ob_get_clean();

    expect( $output )->toBe( '' );
});

test('render outputs settings form for authorized user', function () {
    stub_wp_rendering();
    Functions\when( 'current_user_can' )->justReturn( true );
    Functions\when( 'get_option' )->justReturn( '' );

    $config = Mockery::mock( Kistn_Config::class );
    $config->allows( 'is_valid' )->andReturn( true );
    $config->allows( 'is_constant' )->andReturn( false );
    $config->allows( 'schedule_mode' )->andReturn( 'wp-cron' );

    ob_start();
    ( new Kistn_Settings_Page( $config ) )->render();
    $output = ob_get_clean();

    expect( $output )->not()->toBeEmpty();
});

test('render shows incomplete config notice when config is invalid', function () {
    stub_wp_rendering();
    Functions\when( 'current_user_can' )->justReturn( true );
    Functions\when( 'get_option' )->justReturn( '' );

    $config = Mockery::mock( Kistn_Config::class );
    $config->allows( 'is_valid' )->andReturn( false );
    $config->allows( 'is_constant' )->andReturn( false );
    $config->allows( 'schedule_mode' )->andReturn( 'wp-cron' );

    ob_start();
    ( new Kistn_Settings_Page( $config ) )->render();
    $output = ob_get_clean();

    expect( $output )->toContain( 'notice-warning' );
});

test('render shows wp-cli crontab hint when schedule_mode is wp-cli', function () {
    stub_wp_rendering();
    Functions\when( 'current_user_can' )->justReturn( true );
    Functions\when( 'get_option' )->justReturn( '' );

    $config = Mockery::mock( Kistn_Config::class );
    $config->allows( 'is_valid' )->andReturn( true );
    $config->allows( 'is_constant' )->andReturn( false );
    $config->allows( 'schedule_mode' )->andReturn( 'wp-cli' );

    ob_start();
    ( new Kistn_Settings_Page( $config ) )->render();
    $output = ob_get_clean();

    expect( $output )->toContain( 'wp inventory push' );
});

test('render shows error notice when kistn_last_error is set', function () {
    stub_wp_rendering();
    Functions\when( 'current_user_can' )->justReturn( true );
    Functions\when( 'get_option' )->alias( static function ( string $option, mixed $default = false ): mixed {
        return 'kistn_last_error' === $option ? 'API connection failed' : '';
    } );

    $config = Mockery::mock( Kistn_Config::class );
    $config->allows( 'is_valid' )->andReturn( true );
    $config->allows( 'is_constant' )->andReturn( false );
    $config->allows( 'schedule_mode' )->andReturn( 'wp-cron' );

    ob_start();
    ( new Kistn_Settings_Page( $config ) )->render();
    $output = ob_get_clean();

    expect( $output )->toContain( 'notice-error' );
});

test('render saves options and resyncs schedule when valid nonce submitted', function () {
    $_POST = [
        'kistn_nonce'             => 'valid-nonce',
        'kistn_base_url'          => 'https://api.example.com',
        'kistn_project_id'        => 'proj-uuid',
        'kistn_token'             => '',
        'kistn_wpscan_token'      => '',
        'kistn_schedule_mode'     => 'wp-cron',
        'kistn_schedule_interval' => 'daily',
    ];

    stub_wp_rendering();
    Functions\when( 'current_user_can' )->justReturn( true );
    Functions\when( 'sanitize_key' )->returnArg();
    Functions\when( 'wp_unslash' )->returnArg();
    Functions\when( 'wp_verify_nonce' )->justReturn( true );
    Functions\when( 'sanitize_text_field' )->returnArg();
    Functions\when( 'get_option' )->justReturn( '' );
    Functions\expect( 'update_option' )->atLeast()->once();
    Functions\when( 'wp_clear_scheduled_hook' )->justReturn();
    Functions\when( 'wp_schedule_event' )->justReturn();

    $config = Mockery::mock( Kistn_Config::class );
    $config->allows( 'is_valid' )->andReturn( false );
    $config->allows( 'is_constant' )->andReturn( false );
    $config->allows( 'schedule_mode' )->andReturn( 'wp-cron' );
    $config->allows( 'schedule_interval' )->andReturn( 'daily' );

    ob_start();
    ( new Kistn_Settings_Page( $config ) )->render();
    ob_get_clean();

    $_POST = [];

    expect( true )->toBeTrue();
});

test('render skips saving constant-locked fields', function () {
    $_POST = [
        'kistn_nonce'    => 'valid-nonce',
        'kistn_base_url' => 'should-be-ignored',
    ];

    stub_wp_rendering();
    Functions\when( 'current_user_can' )->justReturn( true );
    Functions\when( 'sanitize_key' )->returnArg();
    Functions\when( 'wp_unslash' )->returnArg();
    Functions\when( 'wp_verify_nonce' )->justReturn( true );
    Functions\when( 'sanitize_text_field' )->returnArg();
    Functions\when( 'get_option' )->justReturn( '' );
    Functions\when( 'wp_clear_scheduled_hook' )->justReturn();
    Functions\when( 'wp_schedule_event' )->justReturn();

    // update_option must not be called for locked 'base_url'
    Functions\expect( 'update_option' )
        ->zeroOrMoreTimes()
        ->with(
            Mockery::not( 'kistn_base_url' ),
            Mockery::any()
        );

    $config = Mockery::mock( Kistn_Config::class );
    $config->allows( 'is_valid' )->andReturn( false );
    $config->allows( 'is_constant' )->andReturnUsing(
        static fn( string $key ): bool => 'base_url' === $key
    );
    $config->allows( 'schedule_mode' )->andReturn( 'wp-cron' );
    $config->allows( 'schedule_interval' )->andReturn( 'daily' );

    ob_start();
    ( new Kistn_Settings_Page( $config ) )->render();
    ob_get_clean();

    $_POST = [];

    expect( true )->toBeTrue();
});
