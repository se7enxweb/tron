<?php
/**
 * Programs and tools switched off by an administrator (Setup > tron > Programs and tools).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronSwitchesTest extends TronTestCase
{
    public function testStoreSwitches(): void
    {
        $db = tronDbPdo::sqliteMemory();
        $s = new tronSwitches( $db, function () { return TronFixture::NOW; } );
        $s->set( 'program', 'katalyst', false, TronFixture::ADMIN );
        $s->set( 'tool', 'centre_ticket_close', false, TronFixture::ADMIN );
        $s->set( 'tool', 'centre_ticket_close', true, TronFixture::ADMIN );
        $s->set( 'tool', 'centre_ticket_link', false, TronFixture::ADMIN );
        $this->assertSame( array( 'katalyst' ), $s->off( 'program' ) );
        $this->assertSame( array( 'centre_ticket_link' ), $s->off( 'tool' ) );
        $all = $s->all();
        $this->assertSame( '1', (string)$all['tool']['centre_ticket_close']['enabled'], 'switching back on keeps one row' );
        $this->assertSame( TronFixture::NOW, (int)$all['program']['katalyst']['changed'] );
        $this->assertThrows( function () use ( $s ) { $s->set( 'program', 'tron', false, 1 ); }, 'InvalidArgumentException', 'core' );
        $this->assertThrows( function () use ( $s ) { $s->set( 'tool', 'tron_status', false, 1 ); }, 'InvalidArgumentException', 'core' );
        $this->assertThrows( function () use ( $s ) { $s->set( 'tool', 'Bad Name', false, 1 ); }, 'InvalidArgumentException', 'not a tool name' );
        $this->assertThrows( function () use ( $s ) { $s->set( 'thing', 'x', false, 1 ); }, 'InvalidArgumentException', 'kind' );
    }

    public function testSwitchedOffProgramsAndToolsAreLeftOut(): void
    {
        $f = TronFixture::build( array( 'switchedOff' => array( 'program' => array( 'katalyst', 'tron' ), 'tool' => array( 'centre_ticket_close', 'tron_status', 'nope_tool' ) ) ) );
        $states = array_column( $f['server']->option( 'programs' ), 'state', 'name' );
        $reasons = array_column( $f['server']->option( 'programs' ), 'reason', 'name' );
        $this->assertSame( 'disabled', $states['katalyst'] );
        $this->assertSame( 'switched off in Setup > tron', $reasons['katalyst'] );
        $this->assertSame( 'loaded', $states['tron'], 'the core cannot be switched off' );
        $reg = $f['server']->registry();
        $this->assertSame( null, $reg->findTool( 'centre_ticket_close' ) );
        $this->assertTrue( $reg->findTool( 'tron_status' ) !== null, 'core tools stay' );
        $this->assertSame( 'switched off in Setup > tron', $reg->unavailableTools()['centre_ticket_close'] );
        $r = TronFixture::call( $f['server'], TronFixture::session( TronFixture::ADMIN ), 'centre_ticket_close', array( 'id' => 1 ) );
        $this->assertSame( tronProtocol::INVALID_PARAMS, $r['error']['code'], 'a switched-off tool is unknown to clients' );
        $this->assertCount( 24, $reg->tools(), '28 - 3 katalyst - 1 switched off' );
    }

    public function testSchemaHasTheSwitchTable(): void
    {
        $this->assertContains( 'CREATE UNIQUE INDEX tron_switch_name ON tron_switch (kind, name)', tronSchema::sql( 'pgsql' ) );
    }
}
