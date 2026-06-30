<?php

use Brain\Monkey\Functions;

function make_config_helper(array $constants = [], array $options = []): Kistn_Config_Helper {
    $helper = Mockery::mock( Kistn_Config_Helper::class );

    $helper->allows( 'is_constant_defined' )->andReturnUsing(
        static fn( string $name ): bool => array_key_exists( $name, $constants )
    );

    $helper->allows( 'get_constant' )->andReturnUsing(
        static fn( string $name ): mixed => $constants[ $name ] ?? null
    );

    $helper->allows( 'get_wp_option' )->andReturnUsing(
        static fn( string $option, mixed $default ): mixed => $options[ $option ] ?? $default
    );

    return $helper;
}

test('reads base_url from option when constant not defined', function () {
    $config = new Kistn_Config( make_config_helper( options: [ 'kistn_base_url' => 'https://example.com' ] ) );
    expect( $config->base_url() )->toBe( 'https://example.com' );
});

test('constant overrides option for base_url', function () {
    $config = new Kistn_Config( make_config_helper(
        constants: [ 'KISTN_BASE_URL' => 'https://from-constant.com' ],
        options: [ 'kistn_base_url' => 'https://from-option.com' ],
    ) );
    expect( $config->base_url() )->toBe( 'https://from-constant.com' );
});

test('trailing slash stripped from base_url', function () {
    $config = new Kistn_Config( make_config_helper( options: [ 'kistn_base_url' => 'https://example.com/' ] ) );
    expect( $config->base_url() )->toBe( 'https://example.com' );
});

test('wpscan_token returns null when empty string', function () {
    $config = new Kistn_Config( make_config_helper() );
    expect( $config->wpscan_token() )->toBeNull();
});

test('wpscan_token returns value when set', function () {
    $config = new Kistn_Config( make_config_helper( options: [ 'kistn_wpscan_token' => 'tok-abc' ] ) );
    expect( $config->wpscan_token() )->toBe( 'tok-abc' );
});

test('is_valid returns false when base_url missing', function () {
    $config = new Kistn_Config( make_config_helper() );
    expect( $config->is_valid() )->toBeFalse();
});

test('is_valid returns true when all required fields present', function () {
    $config = new Kistn_Config( make_config_helper( options: [
        'kistn_base_url'   => 'https://example.com',
        'kistn_project_id' => 'uuid-123',
        'kistn_token'      => 'tok-abc',
    ] ) );
    expect( $config->is_valid() )->toBeTrue();
});

test('is_constant returns true only for defined key', function () {
    $config = new Kistn_Config( make_config_helper(
        constants: [ 'KISTN_BASE_URL' => 'https://example.com' ],
    ) );
    expect( $config->is_constant( 'base_url' ) )->toBeTrue();
    expect( $config->is_constant( 'project_id' ) )->toBeFalse();
});

test('project_id returns configured project id', function () {
    $config = new Kistn_Config( make_config_helper( options: [ 'kistn_project_id' => 'uuid-xyz' ] ) );
    expect( $config->project_id() )->toBe( 'uuid-xyz' );
});

test('token returns configured api token', function () {
    $config = new Kistn_Config( make_config_helper( options: [ 'kistn_token' => 'bearer-tok' ] ) );
    expect( $config->token() )->toBe( 'bearer-tok' );
});

test('schedule_mode returns configured schedule mode', function () {
    $config = new Kistn_Config( make_config_helper( options: [ 'kistn_schedule_mode' => 'wp-cli' ] ) );
    expect( $config->schedule_mode() )->toBe( 'wp-cli' );
});

test('schedule_interval returns configured schedule interval', function () {
    $config = new Kistn_Config( make_config_helper( options: [ 'kistn_schedule_interval' => 'hourly' ] ) );
    expect( $config->schedule_interval() )->toBe( 'hourly' );
});

test('is_valid returns false when schedule_mode is invalid', function () {
    $config = new Kistn_Config( make_config_helper( options: [
        'kistn_base_url'       => 'https://example.com',
        'kistn_project_id'     => 'uuid-123',
        'kistn_token'          => 'tok-abc',
        'kistn_schedule_mode'  => 'invalid-mode',
    ] ) );
    expect( $config->is_valid() )->toBeFalse();
});

test('is_valid returns false when schedule_interval is invalid', function () {
    $config = new Kistn_Config( make_config_helper( options: [
        'kistn_base_url'          => 'https://example.com',
        'kistn_project_id'        => 'uuid-123',
        'kistn_token'             => 'tok-abc',
        'kistn_schedule_interval' => 'weekly',
    ] ) );
    expect( $config->is_valid() )->toBeFalse();
});
