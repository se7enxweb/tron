<?php
/**
 * Example 4: tie the work together. Link a ticket to a gnubus message, a session to-do and a commit, then find the
 * ticket again from the message id.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

require __DIR__ . '/../bootstrap.php';

$w = TronFixture::build();
$s = TronFixture::session( TronFixture::EDITOR );

$t = example_tool( $w['server'], $s, 'centre_ticket_create', array( 'title' => 'Valkey failover on web' ) );
$links = array(
    array( 'gnubus', 'gb-20261124T101500Z-cloud-1a2b', 'handoff from cloud' ),
    array( 'todo', 'claude@cluster#301', 'session to-do' ),
    array( 'commit', 'se7enxweb/tron@f99e348', 'the fix' ),
);
foreach ( $links as $l )
{
    $r = example_tool( $w['server'], $s, 'centre_ticket_link', array( 'id' => $t['id'], 'kind' => $l[0], 'ref' => $l[1], 'note' => $l[2] ) );
    echo "linked {$r['link']['kind']} {$r['link']['ref']}\n";
}

$found = example_tool( $w['server'], $s, 'centre_links_find', array( 'kind' => 'gnubus', 'ref' => 'gb-20261124T101500Z-cloud-1a2b' ) );
foreach ( $found['tickets'] as $f )
    echo "the message belongs to #{$f['ticket_id']} \"{$f['title']}\" ({$f['status']})\n";

$bad = example_tool( $w['server'], $s, 'centre_ticket_link', array( 'id' => $t['id'], 'kind' => 'gnubus', 'ref' => 'not-a-bus-id' ) );
echo "refused: $bad\n";
