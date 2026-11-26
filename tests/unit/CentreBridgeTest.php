<?php
/**
 * The centre tools in front of expcentre's API (centre plan, "MCP contract for tron"), against a stand-in that
 * answers as the contract says: {"ok":true,"result":...} or {"ok":false,"error":{"code","message"}}.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronCentreBridgeTest extends TronTestCase
{
    /** @var array[] calls the stand-in received */ private $calls = array();

    private function build( array $settings = array() ): array
    {
        $cards = array();
        $api = function ( string $tool, array $args, int $userId ) use ( &$cards ) {
            $this->calls[] = array( $tool, $args, $userId );
            switch ( $tool )
            {
                case 'centre_whoami':
                    return array( 'ok' => true, 'result' => array( 'user_id' => $userId, 'grades' => array( 'EXP' => 'member' ) ) );
                case 'centre_create_card':
                    if ( $args['prefix'] !== 'EXP' )
                        return array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => 'no project ' . $args['prefix'] ) );
                    $n = 'EXP-' . ( count( $cards ) + 1 );
                    $cards[$n] = array( 'number' => $n, 'title' => $args['title'], 'lane' => $args['lane'] ?? 'Backlog' );
                    return array( 'ok' => true, 'result' => array( 'number' => $n ) );
                case 'centre_get_card':
                    return isset( $cards[$args['number']] ) ? array( 'ok' => true, 'result' => $cards[$args['number']] )
                                                           : array( 'ok' => false, 'error' => array( 'code' => 'not_found', 'message' => 'no card' ) );
                case 'centre_search_cards':
                    return array( 'ok' => true, 'result' => array_values( $cards ) );
                case 'centre_record_decision':
                    return array( 'ok' => false, 'error' => array( 'code' => 'forbidden', 'message' => 'needs centre/decide' ) );
                case 'centre_log_time':
                    return 'garbage';
            }
            return array( 'ok' => true, 'result' => array( 'tool' => $tool ) );
        };
        return TronFixture::build( array( 'services' => array( 'centre.api' => function () use ( $api ) { return $api; } ), 'settings' => $settings ) );
    }

    private function call( array $f, int $user, string $tool, array $args = array() ): array
    {
        return TronFixture::call( $f['server'], TronFixture::session( $user ), $tool, $args );
    }

    public function testContractToolsReplaceTheTronTables(): void
    {
        $f = $this->build();
        $names = array_map( function ( $t ) { return $t->name; }, $f['server']->registry()->tools() );
        foreach ( array_keys( tronCentreBridge::contract() ) as $n )
            $this->assertContains( $n, $names );
        $this->assertNotContains( 'centre_ticket_create', $names, 'tron\'s own ticket tools are not offered next to centre\'s' );
        $this->assertCount( 29, tronCentreBridge::contract(), 'the 17 tools of the contract table and the 12 of centre 0.0.0.2' );
        $create = $f['server']->registry()->findTool( 'centre_create_card' );
        $this->assertTrue( $create->write );
        $this->assertSame( array( 'centre', 'api' ), $create->policy );
        $this->assertFalse( $f['server']->registry()->findTool( 'centre_get_board' )->write );
    }

    public function testCallsPassThroughAsTheSessionUser(): void
    {
        $f = $this->build();
        $r = $this->call( $f, TronFixture::ADMIN, 'centre_create_card', array( 'prefix' => 'EXP', 'title' => 'make ticket', 'idempotency_key' => 'abcdefgh1' ) );
        $this->assertSame( 'EXP-1', TronFixture::data( $r )['number'] );
        $this->assertSame( array( 'centre_create_card', array( 'prefix' => 'EXP', 'title' => 'make ticket', 'idempotency_key' => 'abcdefgh1' ), TronFixture::ADMIN ), end( $this->calls ) );
        $this->assertSame( 'make ticket', TronFixture::data( $this->call( $f, TronFixture::ADMIN, 'centre_get_card', array( 'number' => 'EXP-1' ) ) )['title'] );
        $list = TronFixture::data( $this->call( $f, TronFixture::ADMIN, 'centre_search_cards', array( 'query' => 'make' ) ) );
        $this->assertSame( 'EXP-1', $list['result'][0]['number'], 'a list result is wrapped in an object' );
        $who = TronFixture::data( $this->call( $f, TronFixture::ADMIN, 'centre_whoami' ) );
        $this->assertSame( TronFixture::ADMIN, $who['user_id'] );
    }

    public function testCentreErrorsBecomeToolErrors(): void
    {
        $f = $this->build();
        $r = $this->call( $f, TronFixture::ADMIN, 'centre_create_card', array( 'prefix' => 'NOPE', 'title' => 'x' ) );
        $this->assertTrue( $r['result']['isError'] );
        $this->assertContains( 'not_found: no project NOPE', $r['result']['content'][0]['text'] );
        $d = $this->call( $f, TronFixture::ADMIN, 'centre_record_decision', array( 'number' => 'EXP-1', 'decision' => 'x' ) );
        $this->assertContains( 'forbidden: needs centre/decide', $d['result']['content'][0]['text'] );
        $g = $this->call( $f, TronFixture::ADMIN, 'centre_log_time', array( 'number' => 'EXP-1', 'minutes' => 5 ) );
        $this->assertContains( 'internal error', $g['result']['content'][0]['text'], 'a malformed answer is an internal error' );
        $bad = $this->call( $f, TronFixture::ADMIN, 'centre_get_card', array( 'number' => 'exp-1' ) );
        $this->assertContains( 'does not match', $bad['result']['content'][0]['text'], 'tron validates the contract shapes before centre is called' );
        $this->assertSame( 'centre_log_time', end( $this->calls )[0], 'the invalid call never reached centre' );
    }

    public function testTronGateBeforeCentre(): void
    {
        $f = $this->build();
        $before = count( $this->calls );
        $r = $this->call( $f, TronFixture::EDITOR, 'centre_get_board', array( 'prefix' => 'EXP' ) );
        $this->assertContains( 'Permission denied', $r['result']['content'][0]['text'], 'without centre/api tron refuses before centre is asked' );
        $this->assertSame( $before, count( $this->calls ) );
    }

    public function testBackendSetting(): void
    {
        $f = $this->build( array( 'CentreSettings' => array( 'Backend' => 'tron' ) ) );
        $this->assertTrue( $f['server']->registry()->findTool( 'centre_ticket_create' ) !== null, 'Backend=tron keeps tron\'s tables even with expcentre present' );
        $this->assertSame( null, $f['server']->registry()->findTool( 'centre_create_card' ) );
        $g = TronFixture::build( array( 'settings' => array( 'CentreSettings' => array( 'Backend' => 'expcentre' ) ) ) );
        $this->assertSame( null, $g['server']->registry()->findTool( 'centre_ticket_create' ) );
        $this->assertContains( 'expcentre', $g['server']->registry()->unavailableTools()['centre_create_card'] );
    }
}
