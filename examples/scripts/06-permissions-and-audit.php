<?php
/**
 * Example 6: roles decide, the audit remembers. The same three calls as a viewer and as an editor, then the audit
 * entries they left.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

require __DIR__ . '/../bootstrap.php';

$w = TronFixture::build();
foreach ( array( TronFixture::VIEWER => 'viewer', TronFixture::EDITOR => 'editor' ) as $id => $login )
{
    $s = TronFixture::session( $id );
    $list = example_tool( $w['server'], $s, 'centre_ticket_list' );
    $create = example_tool( $w['server'], $s, 'centre_ticket_create', array( 'title' => "made by $login", 'body' => 'password=hunter2' ) );
    $katalyst = example_tool( $w['server'], $s, 'katalyst_plans_list' );
    printf( "%-7s list: %s | create: %s | katalyst: %s\n", $login,
            is_array( $list ) ? 'ok' : 'denied', is_array( $create ) ? 'ok #' . $create['id'] : 'denied', is_array( $katalyst ) ? 'ok' : 'denied' );
}
echo "audit:\n";
foreach ( $w['audit']->entries as $e )
    printf( "  %-7s %-22s %s\n", $e['login'], $e['target'], $e['outcome'] );
$started = array_values( array_filter( $w['audit']->entries, function ( $e ) { return $e['outcome'] === 'started'; } ) )[0];
$detail = json_decode( $started['detail'], true );
echo 'the editor\'s create was logged before it ran, with body: ' . json_encode( $detail['args']['body'] ?? null ) . "\n";
