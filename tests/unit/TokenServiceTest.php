<?php
/**
 * Per-user tokens: made once, stored as a hash, expire, revoke, follow the user.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronTokenServiceTest extends TronTestCase
{
    /** @var tronDb */ private $db;
    /** @var int */ private $now = TronFixture::NOW;

    private function service(): tronTokenService
    {
        return new tronTokenService( $this->db, new tronArrayUserDirectory( TronFixture::users() ), function () { return $this->now; } );
    }

    public function setUp(): void
    {
        $this->db = tronDbPdo::sqliteMemory();
        $this->now = TronFixture::NOW;
    }

    public function testCreateAndAuthenticate(): void
    {
        $s = $this->service();
        $r = $s->create( TronFixture::EDITOR, 'claude@cloud', 30, array( 'centre' ) );
        $this->assertTrue( (bool)preg_match( tronTokenService::PATTERN, $r['token'] ) );
        $this->assertSame( TronFixture::NOW + 30 * 86400, $r['expires'] );
        $rows = $this->db->select( 'SELECT * FROM tron_token' );
        $this->assertCount( 1, $rows );
        $this->assertSame( hash( 'sha256', $r['token'] ), $rows[0]['token_hash'] );
        $this->assertNotContains( substr( $r['token'], 18 ), json_encode( $rows ), 'the secret part is not stored' );

        $p = $s->authenticate( $r['token'] );
        $this->assertSame( TronFixture::EDITOR, $p->userId );
        $this->assertSame( 'editor', $p->login );
        $this->assertSame( $r['id'], $p->tokenId );
        $this->assertSame( array( 'centre' ), $p->scopes );
        $this->assertTrue( $p->inScope( 'centre' ) );
        $this->assertTrue( $p->inScope( 'tron' ), 'the core is always in scope' );
        $this->assertFalse( $p->inScope( 'katalyst' ) );
        $this->assertSame( TronFixture::NOW, (int)$this->db->select( 'SELECT last_used FROM tron_token' )[0]['last_used'] );
    }

    public function testRefusals(): void
    {
        $s = $this->service();
        $r = $s->create( TronFixture::VIEWER, 'x', 1 );
        $reason = null;
        $this->assertSame( null, $s->authenticate( 'nonsense', $reason ) );
        $this->assertSame( 'malformed token', $reason );
        $wrong = substr( $r['token'], 0, -1 ) . ( substr( $r['token'], -1 ) === 'A' ? 'B' : 'A' );
        $this->assertSame( null, $s->authenticate( $wrong, $reason ) );
        $this->assertContains( 'unknown token', $reason );
        $this->now += 86400;
        $this->assertSame( null, $s->authenticate( $r['token'], $reason ) );
        $this->assertContains( 'expired', $reason );
        $this->now = TronFixture::NOW;
        $this->assertTrue( $s->revoke( $r['prefix'] ) );
        $this->assertFalse( $s->revoke( $r['prefix'] ), 'revoking twice reports nothing to revoke' );
        $this->assertSame( null, $s->authenticate( $r['token'], $reason ) );
        $this->assertContains( 'revoked', $reason );
    }

    public function testDisabledUsers(): void
    {
        $s = $this->service();
        $this->assertThrows( function () use ( $s ) { $s->create( TronFixture::DISABLED, 'x', 1 ); }, 'InvalidArgumentException', 'disabled' );
        $this->assertThrows( function () use ( $s ) { $s->create( 999, 'x', 1 ); }, 'InvalidArgumentException', 'no user' );
        $this->assertThrows( function () use ( $s ) { $s->create( TronFixture::VIEWER, '', 1 ); }, 'InvalidArgumentException', 'name' );
        $this->assertThrows( function () use ( $s ) { $s->create( TronFixture::VIEWER, 'x', 0 ); }, 'InvalidArgumentException', 'day' );
        $this->assertThrows( function () use ( $s ) { $s->create( TronFixture::VIEWER, 'x', 1, array( 'Bad!' ) ); }, 'InvalidArgumentException', 'program name' );
        // a token whose user is disabled later stops working
        $r = $s->create( TronFixture::VIEWER, 'x', 5 );
        $users = TronFixture::users();
        $users[TronFixture::VIEWER]['enabled'] = false;
        $s2 = new tronTokenService( $this->db, new tronArrayUserDirectory( $users ), function () { return $this->now; } );
        $reason = null;
        $this->assertSame( null, $s2->authenticate( $r['token'], $reason ) );
        $this->assertContains( 'disabled', $reason );
    }

    public function testLastUsedIsThrottledAndListing(): void
    {
        $s = $this->service();
        $r = $s->create( TronFixture::ADMIN, 'x', 5 );
        $s->authenticate( $r['token'] );
        $this->now += 30;
        $s->authenticate( $r['token'] );
        $this->assertSame( TronFixture::NOW, (int)$this->db->select( 'SELECT last_used FROM tron_token' )[0]['last_used'], 'not written again within 60 s' );
        $this->now += 31;
        $s->authenticate( $r['token'] );
        $this->assertSame( TronFixture::NOW + 61, (int)$this->db->select( 'SELECT last_used FROM tron_token' )[0]['last_used'] );
        $s->create( TronFixture::VIEWER, 'y', 5, array( 'centre', 'katalyst', 'centre' ) );
        $list = $s->listTokens();
        $this->assertCount( 2, $list );
        $this->assertSame( 'centre,katalyst', $list[0]['scopes'] );
        $this->assertSame( 'viewer', $list[0]['login'] );
        $this->assertSame( 'active', $list[1]['state'] );
        $this->assertFalse( array_key_exists( 'token_hash', $list[0] ), 'the hash is not listed' );
        $this->assertCount( 1, $s->listTokens( TronFixture::ADMIN ) );
        $this->assertSame( array( '*' ), tronTokenService::normaliseScopes( array( 'centre', '*' ) ) );
    }
}
