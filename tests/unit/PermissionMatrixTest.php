<?php
/**
 * Permissions per role: what each role sees in tools/list and what happens when it calls each tool, plus token
 * scopes, policies with limitations and disabled users.
 *
 * Roles (tests/lib/fixture.php): admin (all), viewer (use, centre_read), editor (+ centre_write), decider
 * (+ centre_decide), operator (use, katalyst_read), detail (+ katalyst_detail), useonly (use), limited (use,
 * centre_read with limitations), norole (nothing), a disabled user with the admin role.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronPermissionMatrixTest extends TronTestCase
{
    const CORE = array( 'tron_apply', 'tron_plan', 'tron_policy_check', 'tron_rules', 'tron_status', 'tron_whoami' );
    // the skill program's tools need only tron/use; what a skill reads is decided by the units it runs
    const SKILL = array( 'skill_draft', 'skill_fork', 'skill_list', 'skill_packs', 'skill_run', 'skill_show', 'skill_validate' );
    const CENTRE_READ = array( 'centre_board_view', 'centre_decision_review', 'centre_links_find', 'centre_ticket_get', 'centre_ticket_list' );
    const CENTRE_WRITE = array( 'centre_ticket_close', 'centre_ticket_comment', 'centre_ticket_create', 'centre_ticket_link', 'centre_ticket_move', 'centre_ticket_update' );
    const CENTRE_DECIDE = array( 'centre_decision_record' );
    const KATALYST = array( 'katalyst_orders_list', 'katalyst_plans_list', 'katalyst_services_list' );

    private static function expected( int $user ): array
    {
        $by = array(
            TronFixture::ADMIN => array_merge( self::CORE, self::SKILL, self::CENTRE_READ, self::CENTRE_WRITE, self::CENTRE_DECIDE, self::KATALYST ),
            TronFixture::VIEWER => array_merge( self::CORE, self::SKILL, self::CENTRE_READ ),
            TronFixture::EDITOR => array_merge( self::CORE, self::SKILL, self::CENTRE_READ, self::CENTRE_WRITE ),
            TronFixture::DECIDER => array_merge( self::CORE, self::SKILL, self::CENTRE_READ, self::CENTRE_WRITE, self::CENTRE_DECIDE ),
            TronFixture::OPERATOR => array_merge( self::CORE, self::SKILL, self::KATALYST ),
            TronFixture::DETAIL => array_merge( self::CORE, self::SKILL, self::KATALYST ),
            TronFixture::USEONLY => array_merge( self::CORE, self::SKILL ),
            TronFixture::LIMITED => array_merge( self::CORE, self::SKILL ),
            TronFixture::NOROLE => array(),
            TronFixture::DISABLED => array(),
        );
        $e = $by[$user];
        sort( $e );
        return $e;
    }

    /** A valid argument set for every tool, so a refusal can only come from the permission check. */
    private static function args( string $tool ): array
    {
        $a = array(
            'centre_ticket_create' => array( 'title' => 'perm' ), 'centre_ticket_update' => array( 'id' => 1, 'title' => 'x' ),
            'centre_ticket_comment' => array( 'id' => 1, 'body' => 'c' ), 'centre_ticket_move' => array( 'id' => 1, 'lane' => 'next' ),
            'centre_ticket_close' => array( 'id' => 1 ), 'centre_ticket_get' => array( 'id' => 1 ),
            'centre_decision_record' => array( 'id' => 2, 'decision' => 'd', 'close' => false ),
            'centre_ticket_link' => array( 'id' => 1, 'kind' => 'url', 'ref' => 'https://example.com/' ),
            'centre_links_find' => array( 'kind' => 'url', 'ref' => 'https://example.com/' ),
            'tron_policy_check' => array( 'tool' => 'tron_whoami' ),
            'tron_plan' => array( 'tool' => 'centre_ticket_create', 'arguments' => array( 'title' => 'perm' ) ),
            'skill_show' => array( 'name' => 'centre.tickets.overdue' ), 'skill_validate' => array( 'name' => 'centre.tickets.overdue' ),
            'skill_run' => array( 'name' => 'centre.tickets.overdue' ), 'skill_fork' => array( 'name' => 'centre.tickets.overdue', 'new_name' => 'local.tickets.overdue' ),
            'skill_draft' => array( 'manifest' => array( 'name' => 'local.perm.test' ) ),
        );
        return $a[$tool] ?? array();
    }

    public function testToolsListPerRole(): void
    {
        $f = TronFixture::build();
        foreach ( array_keys( TronFixture::users() ) as $user )
        {
            $r = $f['server']->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ), TronFixture::session( $user ) );
            $this->assertSame( self::expected( $user ), array_column( $r['result']['tools'], 'name' ), 'tools/list for ' . TronFixture::users()[$user]['login'] );
        }
    }

    public function testEveryCallPerRole(): void
    {
        $all = array_merge( self::CORE, self::SKILL, self::CENTRE_READ, self::CENTRE_WRITE, self::CENTRE_DECIDE, self::KATALYST );
        foreach ( array_keys( TronFixture::users() ) as $user )
        {
            $f = TronFixture::build();
            $admin = TronFixture::session( TronFixture::ADMIN );
            TronFixture::call( $f['server'], $admin, 'centre_ticket_create', array( 'title' => 'one' ) );
            TronFixture::call( $f['server'], $admin, 'centre_ticket_create', array( 'title' => 'two', 'kind' => 'decision' ) );
            $allowed = self::expected( $user );
            $login = TronFixture::users()[$user]['login'];
            foreach ( $all as $tool )
            {
                $r = TronFixture::call( $f['server'], TronFixture::session( $user ), $tool, self::args( $tool ) );
                $text = $r['result']['content'][0]['text'] ?? '';
                // tron_plan and tron_apply pass the gate of tron/use; what follows depends on the planned tool
                if ( in_array( $tool, $allowed, true ) && in_array( $tool, array( 'tron_plan', 'tron_apply' ), true ) )
                    $this->assertNotContains( 'needs the policy tron/use', $text, "$login may call $tool" );
                else if ( in_array( $tool, $allowed, true ) && in_array( $tool, self::SKILL, true ) )
                    $this->assertNotContains( 'Permission denied', $text, "$login may call $tool (its own errors aside: no skill is live here)" );
                else if ( in_array( $tool, $allowed, true ) )
                    $this->assertFalse( $r['result']['isError'], "$login may call $tool: $text" );
                else
                {
                    $this->assertTrue( $r['result']['isError'], "$login may not call $tool" );
                    $this->assertContains( 'Permission denied', $text, "$login / $tool" );
                    $this->assertSame( 'denied', end( $f['audit']->entries )['outcome'], "$login / $tool audited as denied" );
                }
            }
        }
    }

    public function testResourcesAndPromptsFollowPolicies(): void
    {
        $f = TronFixture::build();
        TronFixture::call( $f['server'], TronFixture::session( TronFixture::ADMIN ), 'centre_ticket_create', array( 'title' => 'r' ) );
        $op = TronFixture::session( TronFixture::OPERATOR );
        $t = $f['server']->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/templates/list' ), $op );
        $this->assertSame( array(), $t['result']['resourceTemplates'] );
        $read = $f['server']->handle( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => array( 'uri' => 'centre://ticket/1' ) ), $op );
        $this->assertContains( 'Permission denied', $read['error']['message'] );
        $p = $f['server']->handle( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'prompts/list' ), $op );
        $this->assertSame( array(), array_values( array_filter( array_column( $p['result']['prompts'], 'name' ), function ( $n ) { return strpos( $n, 'tron_' ) !== 0; } ) ), 'only the core\'s prompts (tron/use)' );
        $g = $f['server']->handle( array( 'jsonrpc' => '2.0', 'id' => 4, 'method' => 'prompts/get', 'params' => array( 'name' => 'centre_triage' ) ), $op );
        $this->assertContains( 'Permission denied', $g['error']['message'] );
    }

    public function testTokenScopesNarrowTheUser(): void
    {
        $f = TronFixture::build();
        $scoped = TronFixture::session( TronFixture::ADMIN, 'http', '2025-11-25', array( 'katalyst' ) );
        $r = $f['server']->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ), $scoped );
        $names = array_column( $r['result']['tools'], 'name' );
        $expect = array_merge( self::CORE, self::KATALYST );
        sort( $expect );
        $this->assertSame( $expect, $names, 'an admin token scoped to katalyst sees katalyst and the core only' );
        $call = TronFixture::call( $f['server'], $scoped, 'centre_ticket_list' );
        $this->assertSame( tronProtocol::INVALID_PARAMS, $call['error']['code'], 'out of scope looks like an unknown tool' );
        $res = $f['server']->handle( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => array( 'uri' => 'centre://board/main' ) ), $scoped );
        $this->assertSame( tronProtocol::RESOURCE_NOT_FOUND, $res['error']['code'] );
    }

    public function testLimitedPoliciesAreNotEnough(): void
    {
        $checker = TronFixture::access();
        $this->assertSame( tronAccessChecker::LIMITED, $checker->check( new tronPrincipal( TronFixture::LIMITED, 'limited' ), 'tron', 'centre_read' ) );
        $f = TronFixture::build();
        $r = TronFixture::call( $f['server'], TronFixture::session( TronFixture::LIMITED ), 'centre_ticket_list' );
        $this->assertContains( 'Permission denied', $r['result']['content'][0]['text'], 'tron grants no tool on a policy with limitations unless the tool says it understands them' );
        $this->assertTrue( $f['server']->permitted( TronFixture::session( TronFixture::LIMITED ), array( 'tron', 'centre_read' ), true ), 'a tool with limitedOk accepts it' );
    }

    public function testDisabledUserHoldsNothing(): void
    {
        $checker = TronFixture::access();
        $this->assertSame( tronAccessChecker::NO, $checker->check( new tronPrincipal( TronFixture::DISABLED, 'gone' ), 'tron', 'use' ) );
        $this->assertSame( tronAccessChecker::YES, $checker->check( new tronPrincipal( TronFixture::ADMIN, 'admin' ), 'anything', 'at_all' ) );
        $this->assertSame( tronAccessChecker::NO, $checker->check( new tronPrincipal( TronFixture::NOROLE, 'norole' ), 'tron', 'use' ) );
    }
}
