<?php
/**
 * Every centre tool, resource and prompt through the server, as an agent would call them.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronCentreToolsTest extends TronTestCase
{
    /** @var array */ private $f;
    /** @var tronSession */ private $s;

    public function setUp(): void
    {
        $this->f = TronFixture::build();
        $this->s = TronFixture::session( TronFixture::ADMIN );
    }

    private function ok( string $tool, array $args = array() )
    {
        $r = TronFixture::call( $this->f['server'], $this->s, $tool, $args );
        if ( !empty( $r['result']['isError'] ) )
            $this->fail( "$tool failed: " . $r['result']['content'][0]['text'] );
        return TronFixture::data( $r );
    }

    private function err( string $tool, array $args ): string
    {
        $r = TronFixture::call( $this->f['server'], $this->s, $tool, $args );
        $this->assertTrue( $r['result']['isError'], "$tool should fail" );
        $d = json_decode( $r['result']['content'][0]['text'], true );
        return is_array( $d ) && isset( $d['error'] ) ? (string)$d['error'] : $r['result']['content'][0]['text'];
    }

    public function testCreate(): void
    {
        $t = $this->ok( 'centre_ticket_create', array( 'title' => 'First', 'body' => 'Body', 'labels' => array( 'Ops', 'db' ),
                                                       'assignee' => 'editor', 'due' => '2026-12-01', 'priority' => 'high' ) );
        $this->assertSame( 1, $t['id'] );
        $this->assertSame( 'ticket', $t['kind'] );
        $this->assertSame( 'main', $t['board'] );
        $this->assertSame( 'inbox', $t['lane'] );
        $this->assertSame( 'open', $t['status'] );
        $this->assertSame( 'high', $t['priority'] );
        $this->assertSame( array( 'db', 'ops' ), $t['labels'] );
        $this->assertSame( 'editor', $t['assignee'] );
        $this->assertSame( 'admin', $t['created_by'] );
        $this->assertSame( '2026-12-01', $t['due'] );
        $this->assertSame( '2026-11-24T12:00:00Z', $t['created'] );
        $this->assertSame( null, $t['decision_week'] );
        $card = $this->ok( 'centre_ticket_create', array( 'title' => 'Card', 'kind' => 'card', 'board' => 'ops', 'lane' => 'doing' ) );
        $this->assertSame( 'card', $card['kind'] );
        $this->assertSame( 'doing', $card['lane'] );
        $this->assertSame( 0, $card['position'] );
        $d = $this->ok( 'centre_ticket_create', array( 'title' => 'Decide X', 'kind' => 'decision' ) );
        $this->assertSame( '2026-W48', $d['decision_week'], 'a decision defaults to this week' );
        $this->assertContains( 'no user with the login', $this->err( 'centre_ticket_create', array( 'title' => 'x', 'assignee' => 'nobody' ) ) );
        $this->assertContains( 'disabled', $this->err( 'centre_ticket_create', array( 'title' => 'x', 'assignee' => 'gone' ) ) );
        $this->assertContains( 'not a date', $this->err( 'centre_ticket_create', array( 'title' => 'x', 'due' => '2026-02-30' ) ) );
        $this->assertContains( 'must be one of', $this->err( 'centre_ticket_create', array( 'title' => 'x', 'lane' => 'nowhere' ) ) );
        $this->assertContains( 'title: is required', $this->err( 'centre_ticket_create', array() ) );
        $this->assertContains( 'title must not be empty', $this->err( 'centre_ticket_create', array( 'title' => '   ' ) ) );
    }

    public function testUpdateAndReopen(): void
    {
        $this->ok( 'centre_ticket_create', array( 'title' => 'T', 'assignee' => 'editor' ) );
        $t = $this->ok( 'centre_ticket_update', array( 'id' => 1, 'title' => 'T2', 'labels' => array( 'x' ), 'assignee' => '', 'priority' => 'low' ) );
        $this->assertSame( 'T2', $t['title'] );
        $this->assertSame( null, $t['assignee'] );
        $this->assertSame( array( 'x' ), $t['labels'] );
        $this->assertContains( 'Nothing to change', $this->err( 'centre_ticket_update', array( 'id' => 1 ) ) );
        $this->assertContains( 'must be one of "open"', $this->err( 'centre_ticket_update', array( 'id' => 1, 'status' => 'closed' ) ) );
        $this->ok( 'centre_ticket_close', array( 'id' => 1 ) );
        $re = $this->ok( 'centre_ticket_update', array( 'id' => 1, 'status' => 'open' ) );
        $this->assertSame( 'open', $re['status'] );
        $this->assertSame( null, $re['closed'] );
        $full = $this->ok( 'centre_ticket_get', array( 'id' => 1 ) );
        $fields = array_column( $full['history'], 'field' );
        foreach ( array( 'created', 'title', 'labels', 'assignee_id', 'priority', 'status' ) as $field )
            $this->assertContains( $field, $fields );
    }

    public function testCommentMoveClose(): void
    {
        $this->ok( 'centre_ticket_create', array( 'title' => 'A' ) );
        $this->ok( 'centre_ticket_create', array( 'title' => 'B' ) );
        $c = $this->ok( 'centre_ticket_comment', array( 'id' => 1, 'body' => 'Looks good' ) );
        $this->assertSame( 'admin', $c['comment']['by'] );
        $this->assertContains( 'must not be empty', $this->err( 'centre_ticket_comment', array( 'id' => 1, 'body' => ' ' ) ) );

        $m = $this->ok( 'centre_ticket_move', array( 'id' => 1, 'lane' => 'doing' ) );
        $this->assertSame( 'doing', $m['lane'] );
        $this->assertSame( 0, $m['position'] );
        $m2 = $this->ok( 'centre_ticket_move', array( 'id' => 2, 'lane' => 'doing' ) );
        $this->assertSame( 1, $m2['position'], 'appended at the end of the lane' );
        $top = $this->ok( 'centre_ticket_move', array( 'id' => 2, 'lane' => 'doing', 'position' => 0 ) );
        $this->assertSame( 0, $top['position'] );
        $other = $this->ok( 'centre_ticket_move', array( 'id' => 2, 'lane' => 'next', 'board' => 'ops' ) );
        $this->assertSame( 'ops', $other['board'] );

        $closed = $this->ok( 'centre_ticket_close', array( 'id' => 1, 'reason' => 'duplicate' ) );
        $this->assertSame( 'closed', $closed['status'] );
        $this->assertSame( 'duplicate', $closed['closed_reason'] );
        $this->assertSame( '2026-11-24T12:00:00Z', $closed['closed'] );
        $again = $this->ok( 'centre_ticket_close', array( 'id' => 1, 'reason' => 'other' ) );
        $this->assertSame( 'duplicate', $again['closed_reason'], 'closing again changes nothing' );
        $this->assertContains( 'no ticket 9', $this->err( 'centre_ticket_close', array( 'id' => 9 ) ) );
    }

    public function testListAndFilters(): void
    {
        $this->ok( 'centre_ticket_create', array( 'title' => 'Fix the DNS', 'labels' => array( 'dns' ), 'assignee' => 'editor' ) );
        $this->ok( 'centre_ticket_create', array( 'title' => 'Card 50%_off', 'kind' => 'card', 'board' => 'ops' ) );
        $this->ok( 'centre_ticket_create', array( 'title' => 'Closed one' ) );
        $this->ok( 'centre_ticket_close', array( 'id' => 3 ) );
        $all = $this->ok( 'centre_ticket_list' );
        $this->assertSame( 2, $all['total'], 'open by default' );
        $this->assertFalse( isset( $all['items'][0]['body'] ), 'lists carry no bodies' );
        $this->assertSame( 3, $this->ok( 'centre_ticket_list', array( 'status' => 'any' ) )['total'] );
        $this->assertSame( 1, $this->ok( 'centre_ticket_list', array( 'status' => 'closed' ) )['total'] );
        $this->assertSame( 1, $this->ok( 'centre_ticket_list', array( 'kind' => 'card' ) )['total'] );
        $this->assertSame( 1, $this->ok( 'centre_ticket_list', array( 'board' => 'ops' ) )['total'] );
        $this->assertSame( 1, $this->ok( 'centre_ticket_list', array( 'label' => 'dns' ) )['total'] );
        $this->assertSame( 1, $this->ok( 'centre_ticket_list', array( 'assignee' => 'editor' ) )['total'] );
        $this->assertSame( 1, $this->ok( 'centre_ticket_list', array( 'query' => 'dns' ) )['total'], 'case-insensitive' );
        $this->assertSame( 1, $this->ok( 'centre_ticket_list', array( 'query' => '50%_' ) )['total'], 'wildcards are literal' );
        $this->assertSame( 1, $this->ok( 'centre_ticket_list', array( 'query' => '%' ) )['total'], 'a % matches only a literal %' );
        $page = $this->ok( 'centre_ticket_list', array( 'status' => 'any', 'limit' => 1, 'offset' => 1 ) );
        $this->assertCount( 1, $page['items'] );
        $this->assertSame( 3, $page['total'] );
        $this->assertContains( 'must be at most 200', $this->err( 'centre_ticket_list', array( 'limit' => 500 ) ) );
    }

    public function testBoardView(): void
    {
        $this->ok( 'centre_ticket_create', array( 'title' => 'A' ) );
        $this->ok( 'centre_ticket_create', array( 'title' => 'B', 'lane' => 'doing' ) );
        $this->ok( 'centre_ticket_create', array( 'title' => 'C', 'lane' => 'doing' ) );
        $b = $this->ok( 'centre_board_view' );
        $this->assertSame( array( 'inbox', 'next', 'doing', 'waiting', 'done' ), array_column( $b['lanes'], 'lane' ) );
        $this->assertSame( 2, $b['lanes'][2]['total'] );
        $this->assertSame( array( 'B', 'C' ), array_column( $b['lanes'][2]['items'], 'title' ) );
        $one = $this->ok( 'centre_board_view', array( 'per_lane' => 1 ) );
        $this->assertCount( 1, $one['lanes'][2]['items'] );
        $this->assertSame( 2, $one['lanes'][2]['total'] );
    }

    public function testBoardsAndLanesFromSettings(): void
    {
        $this->f = TronFixture::build( array( 'settings' => array( 'CentreSettings' => array( 'Lanes' => array( 'todo', 'done' ), 'Boards' => array( 'main' ) ) ) ) );
        $t = $this->ok( 'centre_ticket_create', array( 'title' => 'x' ) );
        $this->assertSame( 'todo', $t['lane'] );
        $this->assertContains( "There is no board 'ops'", $this->err( 'centre_ticket_create', array( 'title' => 'x', 'board' => 'ops' ) ) );
        $this->assertContains( 'must be one of "todo", "done"', $this->err( 'centre_ticket_move', array( 'id' => 1, 'lane' => 'doing' ) ) );
    }

    public function testDecisionReviewAndRecord(): void
    {
        $this->ok( 'centre_ticket_create', array( 'title' => 'Overdue', 'kind' => 'decision', 'decision_week' => '2026-W46' ) );
        $this->ok( 'centre_ticket_create', array( 'title' => 'Due', 'kind' => 'decision' ) );
        $this->ok( 'centre_ticket_create', array( 'title' => 'Later', 'kind' => 'decision', 'decision_week' => '2026-W52' ) );
        $this->ok( 'centre_ticket_create', array( 'title' => 'Undated', 'kind' => 'decision' ) );
        $this->ok( 'centre_ticket_update', array( 'id' => 4, 'decision_week' => '' ) );
        $this->ok( 'centre_ticket_create', array( 'title' => 'Not a decision' ) );
        $r = $this->ok( 'centre_decision_review' );
        $this->assertSame( '2026-W48', $r['week'] );
        $this->assertSame( array( 'Overdue' ), array_column( $r['overdue'], 'title' ) );
        $this->assertSame( array( 'Due' ), array_column( $r['due'], 'title' ) );
        $this->assertSame( array( 'Later' ), array_column( $r['later'], 'title' ) );
        $this->assertSame( array( 'Undated' ), array_column( $r['undated'], 'title' ) );
        $this->assertSame( array(), $r['decided'] );

        $d = $this->ok( 'centre_decision_record', array( 'id' => 2, 'decision' => 'We do B.' ) );
        $this->assertSame( 'closed', $d['status'] );
        $this->assertSame( 'decided', $d['closed_reason'] );
        $this->assertSame( 'We do B.', $d['decision'] );
        $kept = $this->ok( 'centre_decision_record', array( 'id' => 1, 'decision' => 'Leaning to A', 'close' => false ) );
        $this->assertSame( 'open', $kept['status'] );
        $r2 = $this->ok( 'centre_decision_review', array( 'week' => '2026-W48' ) );
        $this->assertSame( array( 'Due' ), array_column( $r2['decided'], 'title' ) );
        $this->assertSame( array(), $r2['due'] );
        $this->assertSame( array(), $this->ok( 'centre_decision_review', array( 'week' => '2026-W47' ) )['decided'] );
        $this->assertContains( 'not a decision', $this->err( 'centre_decision_record', array( 'id' => 5, 'decision' => 'x' ) ) );
        $this->assertContains( 'does not match', $this->err( 'centre_decision_review', array( 'week' => '2026-48' ) ) );
        $this->assertSame( array( 1795392000, 1795996800 ), tronCentreStore::weekRange( '2026-W48' ), 'W48 = Mon 23 to Mon 30 Nov 2026' );
    }

    public function testLinksBothWays(): void
    {
        $this->ok( 'centre_ticket_create', array( 'title' => 'A' ) );
        $this->ok( 'centre_ticket_create', array( 'title' => 'B' ) );
        $gb = 'gb-20261124T101500Z-cloud-1a2b';
        $l = $this->ok( 'centre_ticket_link', array( 'id' => 1, 'kind' => 'gnubus', 'ref' => $gb, 'note' => 'handoff' ) );
        $this->assertFalse( $l['existed'] );
        $this->assertTrue( $this->ok( 'centre_ticket_link', array( 'id' => 1, 'kind' => 'gnubus', 'ref' => $gb ) )['existed'] );
        $this->ok( 'centre_ticket_link', array( 'id' => 2, 'kind' => 'gnubus', 'ref' => $gb ) );
        $this->ok( 'centre_ticket_link', array( 'id' => 1, 'kind' => 'todo', 'ref' => 'claude@cloud#12' ) );
        $this->ok( 'centre_ticket_link', array( 'id' => 1, 'kind' => 'url', 'ref' => 'https://example.com/x' ) );
        $this->ok( 'centre_ticket_link', array( 'id' => 1, 'kind' => 'ticket', 'ref' => '2' ) );
        $this->ok( 'centre_ticket_link', array( 'id' => 1, 'kind' => 'commit', 'ref' => 'se7enxweb/tron@1a2b3c4' ) );
        $found = $this->ok( 'centre_links_find', array( 'kind' => 'gnubus', 'ref' => $gb ) );
        $this->assertSame( array( 1, 2 ), array_column( $found['tickets'], 'ticket_id' ) );
        $this->assertSame( array( 1 ), array_column( $this->ok( 'centre_links_find', array( 'kind' => 'todo', 'ref' => 'claude@cloud#12' ) )['tickets'], 'ticket_id' ) );
        $full = $this->ok( 'centre_ticket_get', array( 'id' => 1 ) );
        $this->assertSame( array( 'gnubus', 'todo', 'url', 'ticket', 'commit' ), array_column( $full['links'], 'kind' ) );
        $this->assertContains( 'gnubus message id', $this->err( 'centre_ticket_link', array( 'id' => 1, 'kind' => 'gnubus', 'ref' => 'hello' ) ) );
        $this->assertContains( 'session to-do', $this->err( 'centre_ticket_link', array( 'id' => 1, 'kind' => 'todo', 'ref' => 'todo 12' ) ) );
        $this->assertContains( 'http(s) URL', $this->err( 'centre_ticket_link', array( 'id' => 1, 'kind' => 'url', 'ref' => 'javascript:alert(1)' ) ) );
        $this->assertContains( 'itself', $this->err( 'centre_ticket_link', array( 'id' => 1, 'kind' => 'ticket', 'ref' => '1' ) ) );
        $this->assertContains( 'no ticket 99', $this->err( 'centre_ticket_link', array( 'id' => 1, 'kind' => 'ticket', 'ref' => '99' ) ) );
    }

    public function testResourcesAndPrompts(): void
    {
        $this->ok( 'centre_ticket_create', array( 'title' => 'Res' ) );
        $srv = $this->f['server'];
        $tpl = $srv->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/templates/list' ), $this->s );
        $this->assertSame( array( 'centre://board/{board}', 'centre://ticket/{id}' ), array_column( $tpl['result']['resourceTemplates'], 'uriTemplate' ) );
        $read = $srv->handle( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/read', 'params' => array( 'uri' => 'centre://ticket/1' ) ), $this->s );
        $this->assertSame( 'Res', json_decode( $read['result']['contents'][0]['text'], true )['title'] );
        $this->assertSame( 'application/json', $read['result']['contents'][0]['mimeType'] );
        $board = $srv->handle( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'resources/read', 'params' => array( 'uri' => 'centre://board/main' ) ), $this->s );
        $this->assertSame( 'main', json_decode( $board['result']['contents'][0]['text'], true )['board'] );
        $missing = $srv->handle( array( 'jsonrpc' => '2.0', 'id' => 4, 'method' => 'resources/read', 'params' => array( 'uri' => 'centre://ticket/9' ) ), $this->s );
        $this->assertSame( tronProtocol::RESOURCE_NOT_FOUND, $missing['error']['code'] );
        $this->assertSame( 'centre://ticket/9', $missing['error']['data']['uri'] );

        $prompts = $srv->handle( array( 'jsonrpc' => '2.0', 'id' => 5, 'method' => 'prompts/list' ), $this->s );
        $this->assertSame( array( 'centre_triage', 'centre_weekly_review' ), array_values( array_filter( array_column( $prompts['result']['prompts'], 'name' ), function ( $n ) { return strpos( $n, 'centre_' ) === 0; } ) ) );
        $p = $srv->handle( array( 'jsonrpc' => '2.0', 'id' => 6, 'method' => 'prompts/get', 'params' => array( 'name' => 'centre_weekly_review', 'arguments' => array( 'week' => '2026-W48' ) ) ), $this->s );
        $this->assertSame( 'user', $p['result']['messages'][0]['role'] );
        $this->assertContains( 'weekly decision review for 2026-W48', $p['result']['messages'][0]['content']['text'] );
        $t = $srv->handle( array( 'jsonrpc' => '2.0', 'id' => 7, 'method' => 'prompts/get', 'params' => array( 'name' => 'centre_triage' ) ), $this->s );
        $this->assertContains( "lane 'inbox' of the board 'main'", $t['result']['messages'][0]['content']['text'] );
        $u = $srv->handle( array( 'jsonrpc' => '2.0', 'id' => 8, 'method' => 'prompts/get', 'params' => array( 'name' => 'nope' ) ), $this->s );
        $this->assertSame( tronProtocol::INVALID_PARAMS, $u['error']['code'] );
    }

    public function testWithoutDatabaseTheToolsAreLeftOut(): void
    {
        $env = new tronEnvironment();
        $reg = new tronRegistry();
        $reg->begin( 'centre' );
        ( new tronCentreProgram() )->register( $reg, $env );
        $reg->commit();
        $this->assertCount( 0, $reg->tools() );
        $this->assertSame( 12, count( $reg->unavailableTools() ) );
    }
}
