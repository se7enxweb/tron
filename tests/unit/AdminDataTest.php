<?php
/**
 * The data classes of the administration views (Setup > tron, refactor item R7): dashboard, tokens (make, rotate,
 * revoke, filters), programs and tools (switches, the roles that grant each policy), audit (filters, paging, the live
 * tail, the view-parameter addresses) and settings, on SQLite with the fixture's users and clock.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronAdminDataTest extends TronTestCase
{
    /** @var tronDb */ private $db;
    /** @var tronAuditMemory */ private $audit;

    public function setUp(): void
    {
        $this->db = tronDbPdo::sqliteMemory();
        $this->audit = new tronAuditMemory();
    }

    private function context( array $settings = array() ): tronAdminContext
    {
        return new tronAdminContext( $this->db, $settings + array(
            'TokenSettings' => array( 'DefaultLifetimeDays' => '90', 'MaxLifetimeDays' => '365' ),
            'AuditSettings' => array( 'Sinks' => array( 'database' ) ),
            'HTTPSettings' => array( 'Enabled' => 'true', 'SiteAccesses' => array( 'site' ), 'AllowedHosts' => array( '', 'example.com' ) ),
        ), new tronArrayUserDirectory( TronFixture::users() ), array(
            'services' => array( 'katalyst.source' => TronFixture::katalyst() ),
            'clock' => function () { return TronFixture::NOW; },
            'audit' => $this->audit,
            'actor' => array( 'id' => TronFixture::ADMIN, 'login' => 'admin' ),
        ) );
    }

    private function auditRow( string $login, string $transport, string $outcome, string $program, int $ago = 60 ): void
    {
        ( new tronAuditDb( $this->db ) )->record( array(
            'created' => TronFixture::NOW - $ago, 'user_id' => 1, 'login' => $login, 'token_id' => 0, 'transport' => $transport,
            'remote' => '', 'method' => 'tools/call', 'target' => $program . '_x', 'program' => $program, 'outcome' => $outcome,
            'duration_ms' => 3, 'detail' => json_encode( array( 'args' => array( 'id' => 1 ), 'note' => 'n' ) ) ) );
    }

    public function testTokensCreateListRotateRevoke(): void
    {
        $t = new tronAdminTokensData( $this->context() );
        $this->assertSame( 90, $t->defaultDays() );
        $this->assertSame( array_values( array_diff( self::builtinPrograms(), array( 'tron' ) ) ), $t->scopeChoices(), 'every loaded program but the core' );

        $this->assertContains( '1 to 365', $t->create( 'viewer', 'x', 400, array() )['error'] );
        $this->assertContains( 'nobody', $t->create( 'nobody', 'x', 10, array() )['error'] );
        $this->assertContains( 'disabled', $t->create( 'gone', 'x', 10, array() )['error'] );
        $this->assertContains( 'name', $t->create( 'viewer', '', 10, array() )['error'] );

        $a = $t->create( 'viewer', 'claude@test', 30, array( 'centre', 'nope' ) );
        $this->assertSame( array( 'centre' ), $a['scopes'], 'unknown scopes are dropped' );
        $this->assertSame( 'viewer', $a['login'] );
        $this->assertSame( 1, preg_match( tronTokenService::PATTERN, $a['token'] ) );
        $b = $t->create( 'editor', 'other', 10, array() );
        $this->assertSame( array( '*' ), $b['scopes'], 'no scope ticked: all programs' );

        $this->assertCount( 2, $t->listing( '', '' ) );
        $this->assertCount( 1, $t->listing( 'viewer', '' ) );
        $this->assertCount( 0, $t->listing( 'nobody', '' ), 'an unknown login lists nothing, not everything' );

        // rotate: a new token for the same user, name, scopes and lifetime; the old one revoked
        $r = $t->rotate( (int)$a['id'] );
        $this->assertSame( (int)$a['id'], $r['replaced'] );
        $this->assertSame( 'viewer', $r['login'] );
        $this->assertSame( array( 'centre' ), $r['scopes'] );
        $this->assertSame( TronFixture::NOW + 30 * 86400, $r['expires'] );
        $this->assertTrue( $r['token'] !== $a['token'] );
        $tokens = new tronTokenService( $this->db, new tronArrayUserDirectory( TronFixture::users() ), function () { return TronFixture::NOW; } );
        $this->assertSame( null, $tokens->authenticate( $a['token'] ), 'the old token no longer signs in' );
        $this->assertSame( 'viewer', $tokens->authenticate( $r['token'] )->login, 'the new one does' );
        $this->assertContains( 'revoked', $t->rotate( (int)$a['id'] )['error'], 'a revoked token cannot be rotated' );
        $this->assertContains( 'no token', $t->rotate( 999 )['error'] );

        $this->assertCount( 1, $t->listing( 'viewer', 'revoked' ) );
        $this->assertCount( 1, $t->listing( 'viewer', 'active' ) );

        $this->assertSame( 'Token ' . $b['id'] . ' revoked.', $t->revoke( (int)$b['id'] )['notice'] );
        $this->assertSame( 'Token ' . $b['id'] . ' was not active.', $t->revoke( (int)$b['id'] )['error'] );

        // every change is an administrator's action in the audit, without the token itself
        $methods = array_column( $this->audit->entries, 'method' );
        $this->assertSame( array( 'admin/token.create', 'admin/token.create', 'admin/token.rotate', 'admin/token.revoke' ), $methods );
        foreach ( $this->audit->entries as $e )
        {
            $this->assertSame( 'admin', $e['transport'] );
            $this->assertSame( 'admin', $e['login'] );
            $this->assertNotContains( $a['token'], $e['detail'] );
            $this->assertNotContains( $r['token'], $e['detail'] );
        }
    }

    public function testRotateRenewsAnExpiredTokenWithinTheMaximum(): void
    {
        $users = new tronArrayUserDirectory( TronFixture::users() );
        $past = new tronTokenService( $this->db, $users, function () { return TronFixture::NOW - 400 * 86400; } );
        $old = $past->create( TronFixture::EDITOR, 'long', 380 );
        $t = new tronAdminTokensData( $this->context() );
        $this->assertSame( 'expired', $t->listing( 'editor', '' )[0]['state'] );
        $r = $t->rotate( (int)$old['id'] );
        $this->assertSame( 365, $r['days'], 'capped by MaxLifetimeDays' );
        $this->assertSame( 'active', $t->listing( 'editor', 'active' )[0]['state'] );
        $this->assertCount( 1, $t->listing( 'editor', 'revoked' ), 'the expired one is marked revoked too' );
    }

    public function testProgramsRolesAndSwitches(): void
    {
        $this->db->execute( 'CREATE TABLE ezrole ( id INTEGER PRIMARY KEY, name TEXT, version INTEGER )' );
        $this->db->execute( 'CREATE TABLE ezpolicy ( id INTEGER PRIMARY KEY, role_id INTEGER, module_name TEXT, function_name TEXT )' );
        $this->db->execute( 'CREATE TABLE ezpolicy_limitation ( id INTEGER PRIMARY KEY, policy_id INTEGER, identifier TEXT )' );
        foreach ( array( array( 1, 'Administrator', 0 ), array( 2, 'Agents', 0 ), array( 3, 'Draft', 1 ), array( 4, 'Partners', 0 ) ) as $r )
            $this->db->insert( 'ezrole', array( 'id' => $r[0], 'name' => $r[1], 'version' => $r[2] ) );
        foreach ( array( array( 10, 1, '*', '*' ), array( 11, 2, 'tron', 'centre_read' ), array( 12, 3, 'tron', '*' ),
                         array( 13, 4, 'tron', 'centre_read' ), array( 14, 2, 'tron', 'use' ) ) as $p )
            $this->db->insert( 'ezpolicy', array( 'id' => $p[0], 'role_id' => $p[1], 'module_name' => $p[2], 'function_name' => $p[3] ) );
        $this->db->insert( 'ezpolicy_limitation', array( 'id' => 1, 'policy_id' => 13, 'identifier' => 'Section' ) );

        $p = new tronAdminProgramsData( $this->context() );
        $roles = $p->rolesGranting( 'tron', 'centre_read' );
        $this->assertSame( array( 'Administrator', 'Agents', 'Partners' ), array_column( $roles, 'name' ), 'a draft role (version 1) grants nothing' );
        $this->assertSame( array( false, false, true ), array_column( $roles, 'limited' ) );
        $this->assertSame( array( 'Administrator' ), array_column( $p->rolesGranting( 'tron', 'katalyst_detail' ), 'name' ) );

        $d = $p->data();
        $names = array_column( $d['programs'], 'name' );
        foreach ( array( 'tron', 'centre', 'katalyst' ) as $n )
            $this->assertContains( $n, $names );
        $this->assertSame( 28, $d['counts']['tools'] );
        $this->assertSame( 28, $d['counts']['on'] );
        $this->assertTrue( $d['counts']['write'] > 0 );

        // switch a tool off, then the whole katalyst program; both stay listed, as off
        $this->assertSame( 'centre_ticket_close is switched off.', $p->setSwitch( 'tool', 'centre_ticket_close', false )['notice'] );
        $p2 = new tronAdminProgramsData( $this->context() );
        $this->assertSame( 'katalyst is switched off.', $p2->setSwitch( 'program', 'katalyst', false )['notice'] );
        $d = ( new tronAdminProgramsData( $this->context() ) )->data();
        $this->assertSame( 28, $d['counts']['tools'], 'switched-off tools stay listed' );
        $this->assertSame( 24, $d['counts']['on'] );
        $byName = array_column( $d['programs'], null, 'name' );
        $this->assertTrue( $byName['katalyst']['switched_off'] );
        $close = array_column( $byName['centre']['tools_list'], null, 'name' )['centre_ticket_close'];
        $this->assertFalse( $close['enabled'] );
        $this->assertContains( 'core', $p->setSwitch( 'tool', 'tron_status', false )['error'] );
        $this->assertContains( 'kind', $p->setSwitch( 'thing', 'x', false )['error'] );
        $this->assertSame( array( 'admin/switch.off', 'admin/switch.off' ), array_column( $this->audit->entries, 'method' ) );
    }

    public function testProgramsWithoutKernelRoleTablesListNoRoles(): void
    {
        $this->assertSame( array(), ( new tronAdminProgramsData( $this->context() ) )->rolesGranting( 'tron', 'use' ) );
    }

    public function testAuditFiltersPagingAndTail(): void
    {
        for ( $i = 0; $i < 60; $i++ )
            $this->auditRow( $i % 2 ? 'viewer' : 'editor', $i % 3 ? 'stdio' : 'http', $i % 5 ? 'ok' : 'denied', $i % 4 ? 'centre' : 'katalyst' );
        $a = new tronAdminAuditData( $this->context() );

        $this->assertSame( array( 'outcome' => 'ok', 'user' => 'a b/c' ),
                           tronAdminAuditData::filtersFrom( array( 'outcome' => 'ok', 'transport' => 'smtp', 'program' => 'Bad!', 'user' => rawurlencode( 'a b/c' ), 'x' => 'y' ) ),
                           'invalid filters are dropped' );

        $page = $a->page( array() );
        $this->assertSame( 60, $page['total'] );
        $this->assertCount( 50, $page['rows'] );
        $this->assertSame( 1, $page['first'] );
        $this->assertSame( 50, $page['last'] );
        $this->assertSame( '', $page['prev_url'] );
        $this->assertSame( 'tron/audit/(offset)/50', $page['next_url'] );
        $this->assertTrue( $page['live'] );
        $this->assertSame( '{"id":1}', $page['rows'][0]['args'] );
        $this->assertSame( 'n', $page['rows'][0]['note'] );
        $this->assertContains( 'admin', $page['transports'] );

        $second = $a->page( array( 'offset' => '50' ) );
        $this->assertCount( 10, $second['rows'] );
        $this->assertSame( 'tron/audit', $second['prev_url'] );
        $this->assertSame( '', $second['next_url'] );
        $this->assertFalse( $second['live'], 'no live tail on an older page' );

        $f = $a->page( array( 'user' => 'viewer', 'outcome' => 'denied' ) );
        foreach ( $f['rows'] as $r )
        {
            $this->assertSame( 'viewer', $r['login'] );
            $this->assertSame( 'denied', $r['outcome'] );
        }
        $this->assertSame( 6, $f['total'] );
        $this->assertSame( 'tron/audit/(user)/viewer', $f['bases']['outcome'] );
        $this->assertSame( 'tron/audit/(outcome)/denied', $f['bases']['user'] );
        $this->assertSame( 'tron/audit/(outcome)/denied/(user)/viewer/(part)/rows', $f['live_url'] );
        $this->assertSame( 15, $a->page( array( 'program' => 'katalyst' ) )['total'] );
        $this->assertSame( 20, $a->page( array( 'transport' => 'http' ) )['total'] );

        // the live tail: only newer rows, with the page's filters
        $max = $page['max_id'];
        $this->assertCount( 0, $a->tail( array( 'after' => (string)$max ) )['rows'] );
        $this->auditRow( 'viewer', 'http', 'ok', 'centre', 0 );
        $this->auditRow( 'editor', 'http', 'ok', 'centre', 0 );
        $tail = $a->tail( array( 'after' => (string)$max ) );
        $this->assertCount( 2, $tail['rows'] );
        $this->assertSame( $max + 2, $tail['max_id'] );
        $this->assertCount( 1, $a->tail( array( 'after' => (string)$max, 'user' => 'editor' ) )['rows'] );

        $this->assertSame( 'tron/audit/(user)/' . rawurlencode( 'a b' ), tronAdminAuditData::base( array( 'user' => 'a b' ) ) );
    }

    public function testAuditWithoutTableIsEmpty(): void
    {
        $db = new tronDbPdo( new PDO( 'sqlite::memory:' ) );
        $c = new tronAdminContext( $db, array(), new tronArrayUserDirectory( TronFixture::users() ) );
        $page = ( new tronAdminAuditData( $c ) )->page( array() );
        $this->assertSame( 0, $page['total'] );
        $this->assertContains( 'tron_audit', $page['missing'] );
        $d = ( new tronAdminDashboardData( $c ) )->data();
        $this->assertContains( 'tron_token', $d['missing'] );
        $this->assertSame( 0, $d['calls'] );
    }

    public function testDashboard(): void
    {
        $this->auditRow( 'viewer', 'http', 'ok', 'centre', 2 * 86400 );
        $this->auditRow( 'viewer', 'stdio', 'ok', 'centre', 100 );
        $this->auditRow( 'viewer', 'http', 'denied', 'katalyst', 50 );
        $t = new tronAdminTokensData( $this->context() );
        $t->create( 'viewer', 'a', 10, array() );
        $b = $t->create( 'editor', 'b', 10, array() );
        $t->revoke( (int)$b['id'] );
        foreach ( $this->audit->entries as $e )
            ( new tronAuditDb( $this->db ) )->record( $e );

        $d = ( new tronAdminDashboardData( $this->context() ) )->data();
        $this->assertSame( array(), $d['missing'] );
        $this->assertSame( tronVersion::VERSION, $d['version'] );
        $this->assertSame( 2, $d['calls'], 'last 24 h, agents only' );
        $this->assertSame( 3, $d['admin_actions'] );
        $this->assertSame( 1, $d['outcomes']['denied'] );
        $this->assertSame( array( 'active' => 1, 'expired' => 0, 'revoked' => 1 ), $d['tokens'] );
        $this->assertSame( 'katalyst_x', $d['last']['target'], 'the last call is the last agent call, not an admin action' );
        $this->assertSame( count( self::builtinPrograms() ), $d['programs_loaded'] );
        $this->assertSame( 0, $d['programs_failed'] );
        $this->assertSame( 28, $d['tools'] );
        $this->assertTrue( $d['http']['enabled'] );
        $this->assertSame( array( 'example.com' ), $d['http']['hosts'] );
    }

    public function testSettings(): void
    {
        $g = ( new tronAdminSettingsData( $this->context() ) )->groups();
        $this->assertSame( array( 'AuditSettings', 'HTTPSettings', 'TokenSettings' ), array_keys( $g ) );
        $this->assertSame( 'example.com', $g['HTTPSettings']['AllowedHosts'] );
        $this->assertSame( '365', $g['TokenSettings']['MaxLifetimeDays'] );
    }
}
