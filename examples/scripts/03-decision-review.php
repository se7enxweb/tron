<?php
/**
 * Example 3: the weekly decision review. Decisions due this week, one overdue, then one decided.
 * The clock of the test world is Tuesday 2026-11-24, ISO week 2026-W48.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

require __DIR__ . '/../bootstrap.php';

$w = TronFixture::build();
$s = TronFixture::session( TronFixture::DECIDER );

$a = example_tool( $w['server'], $s, 'centre_ticket_create', array( 'title' => 'Which PostgreSQL major for the cluster?', 'kind' => 'decision' ) );
$b = example_tool( $w['server'], $s, 'centre_ticket_create', array( 'title' => 'Keep the old mail relay?', 'kind' => 'decision', 'decision_week' => '2026-W46' ) );

$review = example_tool( $w['server'], $s, 'centre_decision_review' );
echo "week {$review['week']}: " . count( $review['due'] ) . ' due, ' . count( $review['overdue'] ) . ' overdue, ' . count( $review['decided'] ) . " decided\n";

$d = example_tool( $w['server'], $s, 'centre_decision_record', array( 'id' => $a['id'], 'decision' => 'PostgreSQL 19; upgrade after the holidays.' ) );
echo "#{$d['id']} {$d['status']} ({$d['closed_reason']}): {$d['decision']}\n";

$review = example_tool( $w['server'], $s, 'centre_decision_review' );
echo "week {$review['week']}: " . count( $review['due'] ) . ' due, ' . count( $review['overdue'] ) . ' overdue, ' . count( $review['decided'] ) . " decided\n";

// The editor may write tickets but not decide
$e = example_tool( $w['server'], TronFixture::session( TronFixture::EDITOR ), 'centre_decision_record', array( 'id' => $b['id'], 'decision' => 'x' ) );
echo "editor: $e\n";
