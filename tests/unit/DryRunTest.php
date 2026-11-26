<?php
/**
 * Dry runs and their apply (doc/dry-run.md): the transaction dry run of the centre tools, the computed plan of the
 * content and centre-API tools, the binding of a plan (user, token, tool, arguments, lifetime, once), the refusal
 * when the target changed, and the _meta route.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

require_once dirname( __DIR__ ) . '/lib/fuzz.php';
require_once dirname( __DIR__ ) . '/lib/fuzzworld.php';

final class TronDryRunTest extends TronTestCase
{
    const TABLES = array( 'tron_centre_ticket', 'tron_centre_comment', 'tron_centre_link', 'tron_centre_history' );

    private function world(): array
    {
        $content = TronFuzzWorld::content( function () { return TronFixture::NOW; } );
        $f = TronFixture::build( array( 'access' => TronFuzzWorld::access(), 'services' => array( 'content.source' => $content ) ) );
        $f['content'] = $content;
        return $f;
    }

    private function call( array $f, int $user, string $tool, array $args, array $meta = array(), ?tronSession $session = null ): array
    {
        $params = array( 'name' => $tool, 'arguments' => $args );
        if ( $meta )
            $params['_meta'] = $meta;
        $r = $f['server']->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => $params ), $session ?: TronFixture::session( $user ) );
        return $r;
    }

    private static function body( array $r )
    {
        return json_decode( $r['result']['content'][0]['text'], true );
    }

    /** A checksum of every row of the centre tables. */
    private static function checksum( tronDb $db ): string
    {
        return tronRowDiff::hash( tronRowDiff::snapshot( $db, self::TABLES ) );
    }

    public function testTransactionDryRunChangesNothingAndApplyMakesExactlyThePlan(): void
    {
        $f = $this->world();
        $created = self::body( $this->call( $f, TronFixture::ADMIN, 'centre_ticket_create', array( 'title' => 'first', 'body' => 'b' ) ) );
        $before = self::checksum( $f['db'] );
        $args = array( 'id' => $created['id'], 'title' => 'second', 'priority' => 'high' );

        $r = $this->call( $f, TronFixture::EDITOR, 'tron_plan', array( 'tool' => 'centre_ticket_update', 'arguments' => $args ) );
        $this->assertFalse( $r['result']['isError'], 'the dry run answers: ' . $r['result']['content'][0]['text'] );
        $plan = self::body( $r );
        $this->assertSame( $before, self::checksum( $f['db'] ), 'a dry run leaves every row as it was' );
        $this->assertSame( 'transaction', $plan['mode'] );
        $this->assertSame( 1, preg_match( '/^[a-f0-9]{32}$/', $plan['plan_id'] ) );
        $update = array_values( array_filter( $plan['changes'], function ( $c ) { return $c['op'] === 'update'; } ) );
        $this->assertCount( 1, $update );
        $this->assertSame( array( 'from' => 'first', 'to' => 'second' ), $update[0]['columns']['title'] );
        $this->assertSame( array( 'from' => 'normal', 'to' => 'high' ), $update[0]['columns']['priority'] );
        $this->assertSame( array( 'insert' => 2, 'update' => 1, 'delete' => 0 ), $plan['summary'], 'two history rows and the ticket' );
        $this->assertSame( 'second', $plan['preview']['title'], 'the preview is the answer the call would give' );
        $this->assertSame( array( 'dry-run' ), self::outcomes( $f, 'centre_ticket_update' ), 'the dry run is audited as one, nothing started' );

        // apply: the same change, now for real
        $snap = tronRowDiff::snapshot( $f['db'], self::TABLES );
        $a = $this->call( $f, TronFixture::EDITOR, 'tron_apply', array( 'plan_id' => $plan['plan_id'], 'tool' => 'centre_ticket_update', 'arguments' => $args ) );
        $this->assertFalse( $a['result']['isError'], 'apply: ' . $a['result']['content'][0]['text'] );
        $applied = tronRowDiff::diff( $snap, tronRowDiff::snapshot( $f['db'], self::TABLES ) );
        $this->assertSame( tronRowDiff::hash( tronRowDiff::normalize( $plan['changes'], TronCentreDry::VOLATILE, TronCentreDry::REFS ) ),
                           tronRowDiff::hash( tronRowDiff::normalize( $applied, TronCentreDry::VOLATILE, TronCentreDry::REFS ) ), 'the applied change is the planned one' );
        $this->assertSame( 'second', self::body( $a )['result']['title'] );

        // used once
        $again = $this->call( $f, TronFixture::EDITOR, 'tron_apply', array( 'plan_id' => $plan['plan_id'], 'tool' => 'centre_ticket_update', 'arguments' => $args ) );
        $this->assertContains( 'applied already', $again['result']['content'][0]['text'] );
        $this->assertSame( array( 'dry-run', 'started', 'ok', 'started', 'refused' ), self::outcomes( $f, 'centre_ticket_update' ), 'dry run, apply, refused second apply' );
    }

    /** The audit outcomes of one tool, in order. */
    private static function outcomes( array $f, string $tool ): array
    {
        $out = array();
        foreach ( $f['audit']->entries as $e )
            if ( $e['target'] === $tool )
                $out[] = $e['outcome'];
        return $out;
    }

    public function testCreateIsPlannedAndAppliedWithANewId(): void
    {
        $f = $this->world();
        $before = self::checksum( $f['db'] );
        $plan = self::body( $this->call( $f, TronFixture::ADMIN, 'tron_plan', array( 'tool' => 'centre_ticket_create', 'arguments' => array( 'title' => 'planned' ) ) ) );
        $this->assertSame( $before, self::checksum( $f['db'] ) );
        $this->assertSame( array( 'insert' => 2, 'update' => 0, 'delete' => 0 ), $plan['summary'], 'the ticket and its history row' );
        // somebody else creates a ticket in another lane first: the new id moves, the planned change does not
        $this->call( $f, TronFixture::ADMIN, 'centre_ticket_create', array( 'title' => 'other', 'board' => 'ops' ) );
        $a = $this->call( $f, TronFixture::ADMIN, 'tron_apply', array( 'plan_id' => $plan['plan_id'], 'tool' => 'centre_ticket_create', 'arguments' => array( 'title' => 'planned' ) ) );
        $this->assertFalse( $a['result']['isError'], (string)$a['result']['content'][0]['text'] );
        $this->assertSame( 2, self::body( $a )['result']['id'], 'a new id is not a different change' );
    }

    public function testChangedTargetIsRefusedAndNothingChanges(): void
    {
        $f = $this->world();
        $t = self::body( $this->call( $f, TronFixture::ADMIN, 'centre_ticket_create', array( 'title' => 'a' ) ) );
        $args = array( 'id' => $t['id'], 'title' => 'b' );
        $plan = self::body( $this->call( $f, TronFixture::ADMIN, 'tron_plan', array( 'tool' => 'centre_ticket_update', 'arguments' => $args ) ) );
        $this->call( $f, TronFixture::DECIDER, 'centre_ticket_update', array( 'id' => $t['id'], 'title' => 'changed by someone' ) );
        $before = self::checksum( $f['db'] );
        $a = $this->call( $f, TronFixture::ADMIN, 'tron_apply', array( 'plan_id' => $plan['plan_id'], 'tool' => 'centre_ticket_update', 'arguments' => $args ) );
        $this->assertTrue( $a['result']['isError'] );
        $this->assertContains( 'target changed', $a['result']['content'][0]['text'] );
        $this->assertSame( $before, self::checksum( $f['db'] ), 'a refused apply changes nothing' );
        $refused = array_values( array_filter( $f['audit']->entries, function ( $e ) { return $e['outcome'] === 'refused'; } ) );
        $this->assertCount( 1, $refused, 'the refusal is audited' );
    }

    public function testThePlanIsBound(): void
    {
        $f = $this->world();
        $t = self::body( $this->call( $f, TronFixture::ADMIN, 'centre_ticket_create', array( 'title' => 'a' ) ) );
        $args = array( 'id' => $t['id'], 'title' => 'b' );
        $plan = self::body( $this->call( $f, TronFixture::EDITOR, 'tron_plan', array( 'tool' => 'centre_ticket_update', 'arguments' => $args ) ) );
        $apply = function ( int $user, array $a, ?tronSession $s = null, string $tool = 'centre_ticket_update' ) use ( $f, $plan ) {
            $r = $this->call( $f, $user, 'tron_apply', array( 'plan_id' => $plan['plan_id'], 'tool' => $tool, 'arguments' => $a ), array(), $s );
            return $r['result']['content'][0]['text'];
        };
        $this->assertContains( 'Unknown plan id', $apply( TronFixture::DECIDER, $args ), 'another user\'s plan looks unknown' );
        $this->assertContains( 'arguments differ', $apply( TronFixture::EDITOR, array( 'id' => $t['id'], 'title' => 'c' ) ) );
        $this->assertContains( 'not centre_ticket_close', $apply( TronFixture::EDITOR, array( 'id' => $t['id'] ), null, 'centre_ticket_close' ) );
        $tokenSession = new tronSession( new tronPrincipal( TronFixture::EDITOR, 'editor', 77 ), 'http', false, '2025-11-25', 'test' );
        $this->assertContains( 'another token', $apply( TronFixture::EDITOR, $args, $tokenSession ) );
        $f['db']->execute( 'UPDATE tron_plan SET expires = :e', array( 'e' => TronFixture::NOW - 1 ) );
        $this->assertContains( 'expired', $apply( TronFixture::EDITOR, $args ) );
        $this->assertContains( 'Unknown plan id', $this->call( $f, TronFixture::EDITOR, 'tron_apply', array( 'plan_id' => str_repeat( 'f', 32 ), 'tool' => 'centre_ticket_update', 'arguments' => $args ) )['result']['content'][0]['text'] );
        $rows = $f['db']->select( 'SELECT * FROM tron_plan' );
        $this->assertCount( 1, $rows );
        $this->assertSame( 64, strlen( $rows[0]['input_hash'] ), 'only hashes are kept' );
        $this->assertNotContains( '"b"', json_encode( $rows ), 'no argument value is kept' );
    }

    public function testMetaRouteAndRefusals(): void
    {
        $f = $this->world();
        $before = self::checksum( $f['db'] );
        $r = $this->call( $f, TronFixture::ADMIN, 'centre_ticket_create', array( 'title' => 'meta' ), array( 'tron/dryRun' => true ) );
        $plan = self::body( $r );
        $this->assertSame( $before, self::checksum( $f['db'] ), '_meta tron/dryRun changes nothing' );
        $a = $this->call( $f, TronFixture::ADMIN, 'centre_ticket_create', array( 'title' => 'meta' ), array( 'tron/planId' => $plan['plan_id'] ) );
        $this->assertSame( 'meta', self::body( $a )['title'], '_meta tron/planId applies' );
        $this->assertContains( 'only reads', $this->call( $f, TronFixture::ADMIN, 'tron_plan', array( 'tool' => 'centre_ticket_list' ) )['result']['content'][0]['text'] );
        $denied = $this->call( $f, TronFixture::VIEWER, 'tron_plan', array( 'tool' => 'centre_ticket_create', 'arguments' => array( 'title' => 'x' ) ) );
        $this->assertContains( 'Permission denied', $denied['result']['content'][0]['text'], 'a dry run passes the same policy gate as a call' );
        $invalid = $this->call( $f, TronFixture::ADMIN, 'tron_plan', array( 'tool' => 'centre_ticket_create', 'arguments' => array() ) );
        $this->assertContains( 'Invalid arguments', $invalid['result']['content'][0]['text'] );
        $wouldFail = $this->call( $f, TronFixture::ADMIN, 'tron_plan', array( 'tool' => 'centre_ticket_update', 'arguments' => array( 'id' => 999, 'title' => 'x' ) ) );
        $this->assertTrue( $wouldFail['result']['isError'], 'a dry run of a call that would fail fails the same way' );
        $this->assertCount( 1, $f['db']->select( 'SELECT id FROM tron_plan' ), 'refused dry runs leave no plan' );
    }

    public function testContentEditTwice(): void
    {
        $f = $this->world();
        foreach ( array( 'Media, edited once', 'Media' ) as $round => $title )
        {
            $node = $f['content']->node( 43 );
            $before = tronRowDiff::hash( $f['content']->nodes );
            $plan = self::body( $this->call( $f, TronFixture::EDITOR, 'tron_plan', array( 'tool' => 'content_node_update',
                                                                                    'arguments' => array( 'node_id' => 43, 'attributes' => array( 'title' => $title ) ) ) ) );
            $this->assertSame( $before, tronRowDiff::hash( $f['content']->nodes ), "round $round: the dry run changes nothing" );
            $this->assertSame( 'computed', $plan['mode'] );
            $this->assertSame( array( 'kind' => 'attribute', 'target' => 'object 41', 'field' => 'title', 'type' => 'ezstring', 'from' => $node['attributes']['title']['value'], 'to' => $title ), $plan['changes'][0] );
            $this->assertSame( array( 'kind' => 'version', 'target' => 'object 41', 'field' => 'current_version', 'from' => $node['current_version'], 'to' => $node['next_version'] ), $plan['changes'][1] );
            $a = self::body( $this->call( $f, TronFixture::EDITOR, 'tron_apply', array( 'plan_id' => $plan['plan_id'], 'tool' => 'content_node_update',
                                                                                  'arguments' => array( 'node_id' => 43, 'attributes' => array( 'title' => $title ) ) ) ) );
            $this->assertSame( array( array( 'attribute' => 'title', 'from' => $node['attributes']['title']['value'], 'to' => $title ) ), $a['result']['changed'], 'the applied change is the planned one' );
            $this->assertSame( $node['next_version'], $a['result']['version'] );
        }
        $this->assertSame( 5, $f['content']->node( 43 )['current_version'], 'two edits, two versions' );
    }

    public function testContentRefusals(): void
    {
        $f = $this->world();
        $plan = function ( int $user, array $a ) use ( $f ) {
            return $this->call( $f, $user, 'tron_plan', array( 'tool' => 'content_node_update', 'arguments' => $a ) )['result']['content'][0]['text'];
        };
        $this->assertContains( 'may not edit node 2', $plan( TronFixture::EDITOR, array( 'node_id' => 2, 'attributes' => array( 'title' => 'x' ) ) ), 'the kernel\'s object check' );
        $this->assertContains( 'no node 999', $plan( TronFixture::ADMIN, array( 'node_id' => 999, 'attributes' => array( 'title' => 'x' ) ) ) );
        $this->assertContains( 'ezimage', $plan( TronFixture::ADMIN, array( 'node_id' => 2, 'attributes' => array( 'image' => 'x' ) ) ) );
        $this->assertContains( 'Nothing to change', $plan( TronFixture::ADMIN, array( 'node_id' => 2, 'attributes' => array( 'title' => 'Home' ) ) ) );
        $this->assertContains( 'text line', $plan( TronFixture::ADMIN, array( 'node_id' => 2, 'attributes' => array( 'title' => "a\nb" ) ) ) );
        $this->assertContains( 'Permission denied', $plan( TronFixture::VIEWER, array( 'node_id' => 2, 'attributes' => array( 'title' => 'x' ) ) ) );
        // the object changed between dry run and apply
        $args = array( 'node_id' => 2, 'attributes' => array( 'count' => 4 ) );
        $p = json_decode( $plan( TronFixture::ADMIN, $args ), true );
        $f['content']->update( 2, array( 'show' => '0' ), TronFixture::DECIDER );
        $r = $this->call( $f, TronFixture::ADMIN, 'tron_apply', array( 'plan_id' => $p['plan_id'], 'tool' => 'content_node_update', 'arguments' => $args ) );
        $this->assertContains( 'target changed', $r['result']['content'][0]['text'] );
        $this->assertSame( '3', $f['content']->node( 2 )['attributes']['count']['value'] );
    }

    public function testCentreApiWritesPlanTheCall(): void
    {
        $api = TronFuzzWorld::centreApi();
        $f = TronFixture::build( array( 'access' => TronFuzzWorld::access(), 'services' => array( 'centre.api' => function () use ( $api ) { return $api; } ) ) );
        $args = array( 'number' => 'EXP-1', 'fields' => array( 'priority' => 'high' ) );
        $plan = self::body( $this->call( $f, TronFixture::ADMIN, 'tron_plan', array( 'tool' => 'centre_update_card', 'arguments' => $args ) ) );
        $this->assertSame( array( 'kind' => 'card', 'target' => 'EXP-1', 'op' => 'centre_update_card', 'field' => 'priority', 'from' => 'medium', 'to' => 'high' ), $plan['changes'][0] );
        $this->assertSame( 'medium', $api( 'centre_get_card', array( 'number' => 'EXP-1' ), 1 )['result']['priority'], 'the dry run did not call the write' );
        $api( 'centre_update_card', array( 'number' => 'EXP-1', 'fields' => array( 'title' => 'moved on' ) ), 4 );
        $r = $this->call( $f, TronFixture::ADMIN, 'tron_apply', array( 'plan_id' => $plan['plan_id'], 'tool' => 'centre_update_card', 'arguments' => $args ) );
        $this->assertContains( 'target changed', $r['result']['content'][0]['text'], 'the card changed in between' );
        $plan2 = self::body( $this->call( $f, TronFixture::ADMIN, 'tron_plan', array( 'tool' => 'centre_update_card', 'arguments' => $args ) ) );
        $ok = $this->call( $f, TronFixture::ADMIN, 'tron_apply', array( 'plan_id' => $plan2['plan_id'], 'tool' => 'centre_update_card', 'arguments' => $args ) );
        $this->assertFalse( $ok['result']['isError'] );
        $this->assertSame( 'high', $api( 'centre_get_card', array( 'number' => 'EXP-1' ), 1 )['result']['priority'] );
    }

    public function testEveryWriteToolHasADryRun(): void
    {
        foreach ( array( $this->world(), TronFixture::build( array( 'services' => array( 'centre.api' => function () { return TronFuzzWorld::centreApi(); } ) ) ) ) as $f )
            foreach ( $f['server']->registry()->tools() as $t )
                if ( $t->write && $t->name !== 'tron_apply' && in_array( $t->program, array( 'tron', 'centre', 'content', 'katalyst' ), true ) )
                    $this->assertTrue( $t->hasDryRun(), "{$t->name} has a dry run" );
    }

    public function testSavepointsNest(): void
    {
        $db = tronDbPdo::sqliteMemory();
        $db->begin();
        $db->insert( 'tron_switch', array( 'kind' => 'tool', 'name' => 'a', 'enabled' => 1, 'changed' => 1, 'changed_by' => 1 ) );
        $db->begin();
        $db->insert( 'tron_switch', array( 'kind' => 'tool', 'name' => 'b', 'enabled' => 1, 'changed' => 1, 'changed_by' => 1 ) );
        $db->rollback();
        $db->begin();
        $db->insert( 'tron_switch', array( 'kind' => 'tool', 'name' => 'c', 'enabled' => 1, 'changed' => 1, 'changed_by' => 1 ) );
        $db->commit();
        $this->assertSame( array( 'a', 'c' ), array_column( $db->select( 'SELECT name FROM tron_switch ORDER BY name' ), 'name' ), 'inner rollback undoes only its level' );
        $db->rollback();
        $this->assertCount( 0, $db->select( 'SELECT name FROM tron_switch' ), 'the outer rollback undoes everything' );
    }
}

/** The centre's dry-run normalisation, as the tests compare with it. */
final class TronCentreDry
{
    const VOLATILE = array( 'created', 'modified', 'closed' );
    const REFS = array( 'tron_centre_comment.ticket_id' => 'tron_centre_ticket', 'tron_centre_link.ticket_id' => 'tron_centre_ticket',
                        'tron_centre_history.ticket_id' => 'tron_centre_ticket' );
}
