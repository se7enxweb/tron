<?php
/**
 * Example 2: a ticket's life. Create, comment, move across the board, close; then list what is open.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

require __DIR__ . '/../bootstrap.php';

$w = TronFixture::build();
$s = TronFixture::session( TronFixture::EDITOR );

$t = example_tool( $w['server'], $s, 'centre_ticket_create', array(
    'title' => 'Renew the certificate of example.org', 'body' => 'Expires on 2026-12-01.',
    'labels' => array( 'certs', 'ops' ), 'priority' => 'high', 'due' => '2026-11-30' ) );
echo "created #{$t['id']} in {$t['board']}/{$t['lane']}, priority {$t['priority']}, labels " . implode( ',', $t['labels'] ) . "\n";

example_tool( $w['server'], $s, 'centre_ticket_comment', array( 'id' => $t['id'], 'body' => 'DNS-01 challenge prepared.' ) );
$m = example_tool( $w['server'], $s, 'centre_ticket_move', array( 'id' => $t['id'], 'lane' => 'doing' ) );
echo "moved to {$m['lane']} at position {$m['position']}\n";

$second = example_tool( $w['server'], $s, 'centre_ticket_create', array( 'title' => 'Check the renewal timer' ) );
$c = example_tool( $w['server'], $s, 'centre_ticket_close', array( 'id' => $t['id'], 'reason' => 'done' ) );
echo "closed #{$c['id']}: {$c['status']} ({$c['closed_reason']}) at {$c['closed']}\n";

$open = example_tool( $w['server'], $s, 'centre_ticket_list' );
echo "open now: {$open['total']} (#" . implode( ', #', array_column( $open['items'], 'id' ) ) . ")\n";

$full = example_tool( $w['server'], $s, 'centre_ticket_get', array( 'id' => $t['id'] ) );
echo 'history: ' . implode( ', ', array_map( function ( $h ) { return $h['field'] . ( $h['to'] !== '' ? '=' . $h['to'] : '' ); }, $full['history'] ) ) . "\n";
echo 'comments: ' . count( $full['comments'] ) . "\n";
