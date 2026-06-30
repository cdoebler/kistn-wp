<?php

require_once __DIR__ . '/stubs/class-wp-error.php';
require_once __DIR__ . '/stubs/class-wp-theme.php';
require_once __DIR__ . '/stubs/class-wp-cli.php';
require_once __DIR__ . '/stubs/constants.php';

use Brain\Monkey;

uses()->beforeEach(function () {
    Monkey\setUp();
})->afterEach(function () {
    Mockery::close();
    Monkey\tearDown();
})->in('Unit');
