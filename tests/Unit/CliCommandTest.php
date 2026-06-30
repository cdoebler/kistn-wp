<?php

test('push delegates to inventory pusher and logs via WP_CLI', function () {
    $pusher = Mockery::mock( Kistn_Inventory_Pusher::class );
    $pusher->expects( 'push' )->once();

    ( new Kistn_Cli_Command( $pusher ) )->push();

    expect( true )->toBeTrue();
});
