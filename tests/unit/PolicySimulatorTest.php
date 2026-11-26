<?php
/**
 * The policy simulator (doc/policy-simulator.md): tron_policy_check answers exactly what a call would meet, for every
 * user and every tool, without calling anything; it names the gate, the roles and policies and their limitations.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

require_once dirname( __DIR__ ) . '/lib/fuzz.php';
require_once dirname( __DIR__ ) . '/lib/fuzzworld.php';

final class TronPolicySimulatorTest extends TronTestCase
{
    private function world(): array
    {
        $content = TronFuzzWorld::content( function () { return TronFixture::NOW; } );
        return TronFixture::build( array( 'access' => TronFuzzWorld::access(), 'services' => array( 'content.source' => $content ) ) );
    }

    private function check( array $f, int $asUser, array $args ): array
    {
        $r = TronFixture::call( $f['server'], TronFixture::session( $asUser ), 'tron_policy_check', $args );
        $this->assertFalse( $r['result']['isError'], 'tron_policy_check answers: ' . $r['result']['content'][0]['text'] );
        return json_decode( $r['result']['content'][0]['text'], true );
    }

    /**
     * Every user x every tool: the simulator's answer equals what the call meets. A call is "allowed" when it is not
     * refused by policy or scope; write tools are called with no arguments, so an allowed call stops at the argument
     * check and changes nothing.
     */
    public function testEveryUserAndToolMatchesTheRealCall(): void
    {
        $f = $this->world();
        $cases = 0;
        foreach ( array_keys( TronFixture::users() ) as $user )
        {
            $login = TronFixture::users()[$user]['login'];
            foreach ( $f['server']->registry()->tools() as $tool )
            {
                $sim = $this->check( $f, TronFixture::ADMIN, array( 'user' => $login, 'tool' => $tool->name ) )['checks'][0];
                $r = TronFixture::call( $f['server'], TronFixture::session( $user ), $tool->name, array() );
                $text = $r['result']['content'][0]['text'] ?? json_encode( $r['error'] ?? null );
                $live = !( isset( $r['error'] ) || strpos( $text, 'Permission denied: this tool needs the policy' ) !== false );
                $this->assertSame( $live, $sim['allowed'], "$login / {$tool->name}: simulated {$sim['reason']}, live $text" );
                $cases++;
            }
        }
        $this->assertTrue( $cases >= 200, "$cases cases" );
    }

    public function testReasonsMatchesAndLimitations(): void
    {
        $f = $this->world();
        $admin = $this->check( $f, TronFixture::ADMIN, array( 'user' => 'viewer', 'tool' => 'centre_ticket_create' ) )['checks'][0];
        $this->assertFalse( $admin['allowed'] );
        $this->assertSame( 'policy', $admin['decided_by'] );
        $this->assertSame( 'no', $admin['access'] );
        $this->assertSame( 'tron/centre_write', $admin['policy'] );
        $this->assertContains( 'no role of the user grants tron/centre_write', $admin['reason'] );

        $limited = $this->check( $f, TronFixture::ADMIN, array( 'user' => 'limited', 'tool' => 'centre_ticket_list' ) )['checks'][0];
        $this->assertSame( 'limited', $limited['access'] );
        $this->assertFalse( $limited['allowed'], 'a tool that needs the policy without limitations' );
        $this->assertContains( 'only with limitations', $limited['reason'] );
        $this->assertSame( array( 'role' => 'limited', 'policy' => 'tron/centre_read', 'policy_id' => null, 'limitations' => array( 'Limited' => array( 'limited' ) ) ), $limited['matches'][0] );

        $editor = $this->check( $f, TronFixture::ADMIN, array( 'user' => 'editor', 'tool' => 'content_node_update' ) )['checks'][0];
        $this->assertTrue( $editor['allowed'], 'content_node_update takes a limited content/edit' );
        $this->assertSame( array( 'Subtree' => array( '/1/2/43/' ) ), $editor['matches'][0]['limitations'] );
        $this->assertContains( 'give a target', $editor['reason'] );
        $on43 = $this->check( $f, TronFixture::ADMIN, array( 'user' => 'editor', 'tool' => 'content_node_update', 'target' => '43' ) )['checks'][0];
        $this->assertTrue( $on43['allowed'] );
        $this->assertSame( 'target', $on43['decided_by'] );
        $on2 = $this->check( $f, TronFixture::ADMIN, array( 'user' => 'editor', 'tool' => 'content_node_update', 'target' => '2' ) )['checks'][0];
        $this->assertFalse( $on2['allowed'], 'node 2 is outside the editor\'s subtree' );
        $this->assertContains( 'refuses edit', $on2['reason'] );

        $gone = $this->check( $f, TronFixture::ADMIN, array( 'user' => 'gone', 'tool' => 'tron_whoami' ) )['checks'][0];
        $this->assertFalse( $gone['allowed'] );
        $this->assertContains( 'disabled', $gone['reason'] );
    }

    public function testProgramsResourcesPromptsAndScopes(): void
    {
        $f = $this->world();
        $all = $this->check( $f, TronFixture::ADMIN, array( 'user' => 'operator', 'tool' => '*' ) );
        $kinds = array_count_values( array_column( $all['checks'], 'kind' ) );
        $this->assertTrue( $kinds['tool'] >= 20 && $kinds['resource'] >= 4 && $kinds['prompt'] >= 2, json_encode( $kinds ) );
        $allowed = array_column( array_filter( $all['checks'], function ( $c ) { return $c['allowed']; } ), 'name' );
        foreach ( array( 'katalyst_orders_list', 'tron_whoami', 'tron://status' ) as $n )
            $this->assertContains( $n, $allowed );
        $this->assertNotContains( 'centre_triage', $allowed );

        $res = $this->check( $f, TronFixture::ADMIN, array( 'user' => 'viewer', 'tool' => 'centre://ticket/1' ) )['checks'][0];
        $this->assertSame( 'resource', $res['kind'] );
        $this->assertTrue( $res['allowed'] );

        $scoped = $this->check( $f, TronFixture::ADMIN, array( 'user' => 'viewer', 'tool' => 'centre_ticket_list', 'scopes' => array( 'katalyst' ) ) )['checks'][0];
        $this->assertFalse( $scoped['allowed'] );
        $this->assertSame( 'scope', $scoped['decided_by'] );

        $unknown = $this->check( $f, TronFixture::ADMIN, array( 'tool' => 'nope_tool' ) )['checks'][0];
        $this->assertSame( 'unknown', $unknown['decided_by'] );
        $this->assertFalse( $unknown['allowed'] );
    }

    public function testOnlyAnAdministratorChecksSomeoneElse(): void
    {
        $f = $this->world();
        $r = TronFixture::call( $f['server'], TronFixture::session( TronFixture::VIEWER ), 'tron_policy_check', array( 'user' => 'admin', 'tool' => 'tron_status' ) );
        $this->assertTrue( $r['result']['isError'] );
        $this->assertContains( 'tron/admin', $r['result']['content'][0]['text'] );
        $self = $this->check( $f, TronFixture::VIEWER, array( 'tool' => 'centre_ticket_list' ) );
        $this->assertSame( 'viewer', $self['user']['login'] );
        $this->assertTrue( $self['checks'][0]['allowed'] );
        // a session's own token scope cannot be widened by the simulation
        $s = TronFixture::session( TronFixture::VIEWER, 'http', '2025-11-25', array( 'katalyst' ) );
        $wide = json_decode( TronFixture::call( $f['server'], $s, 'tron_policy_check', array( 'tool' => 'centre_ticket_list', 'scopes' => array( '*' ) ) )['result']['content'][0]['text'], true );
        $this->assertFalse( $wide['checks'][0]['allowed'] );
        $this->assertSame( 'scope', $wide['checks'][0]['decided_by'] );
        $this->assertSame( 0, count( array_filter( $f['audit']->entries, function ( $e ) { return $e['target'] !== 'tron_policy_check'; } ) ), 'nothing but the checks was called' );
    }
}
