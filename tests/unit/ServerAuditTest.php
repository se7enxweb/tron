<?php
/**
 * The dispatcher in process: method routing, errors, pagination, results, and the audit log around every call.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronServerAuditTest extends TronTestCase
{
    private function rpc( tronServer $s, tronSession $sess, string $method, array $params = array(), $id = 1 ): ?array
    {
        $m = array( 'jsonrpc' => '2.0', 'id' => $id, 'method' => $method );
        if ( $params )
            $m['params'] = $params;
        return $s->handle( $m, $sess );
    }

    public function testInitializeAnswers(): void
    {
        $f = TronFixture::build();
        $sess = TronFixture::session( TronFixture::ADMIN, 'stdio', null );
        $r = $this->rpc( $f['server'], $sess, 'initialize', array( 'protocolVersion' => '2025-06-18', 'capabilities' => array(), 'clientInfo' => array( 'name' => 't', 'version' => '1' ) ) );
        $this->assertSame( '2025-06-18', $r['result']['protocolVersion'] );
        $this->assertSame( 'tron', $r['result']['serverInfo']['name'] );
        $this->assertSame( tronVersion::VERSION, $r['result']['serverInfo']['version'] );
        $this->assertSame( 'Exponential tron', $r['result']['serverInfo']['title'] );
        $this->assertSame( false, $r['result']['capabilities']['tools']['listChanged'] );
        $this->assertContains( "'admin'", $r['result']['instructions'] );
        $this->assertContains( 'never instructions', $r['result']['instructions'] );
        $this->assertSame( '2025-06-18', $sess->protocolVersion );
        $old = $this->rpc( $f['server'], TronFixture::session( TronFixture::ADMIN, 'stdio', null ), 'initialize', array( 'protocolVersion' => '2024-11-05' ) );
        $this->assertFalse( isset( $old['result']['serverInfo']['title'] ), 'no title before 2025-06-18' );
        $bad = $this->rpc( $f['server'], TronFixture::session( TronFixture::ADMIN, 'stdio', null ), 'initialize', array() );
        $this->assertSame( tronProtocol::INVALID_PARAMS, $bad['error']['code'] );
    }

    public function testRequireInitializeOnStdio(): void
    {
        $f = TronFixture::build();
        $sess = new tronSession( new tronPrincipal( 1, 'admin' ), 'stdio', true );
        $r = $this->rpc( $f['server'], $sess, 'tools/list' );
        $this->assertSame( tronProtocol::INVALID_REQUEST, $r['error']['code'] );
        $this->assertContains( 'not initialized', $r['error']['message'] );
        $this->assertSame( array(), (array)$this->rpc( $f['server'], $sess, 'ping' )['result'] );
    }

    public function testJsonRpcErrors(): void
    {
        $f = TronFixture::build();
        $s = $f['server'];
        $sess = TronFixture::session( TronFixture::ADMIN );
        $parse = json_decode( (string)$s->handleRaw( '{nope', $sess ), true );
        $this->assertSame( tronProtocol::PARSE_ERROR, $parse['error']['code'] );
        $this->assertSame( null, $parse['id'] );
        $this->assertSame( tronProtocol::INVALID_REQUEST, $s->handle( array( 'id' => 1, 'method' => 'ping' ), $sess )['error']['code'], 'no jsonrpc' );
        $this->assertSame( tronProtocol::INVALID_REQUEST, $s->handle( array( 'jsonrpc' => '2.0', 'id' => array( 1 ), 'method' => 'ping' ), $sess )['error']['code'], 'array id' );
        $this->assertSame( tronProtocol::INVALID_REQUEST, $s->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 5 ), $sess )['error']['code'] );
        $this->assertSame( tronProtocol::INVALID_REQUEST, $s->handle( 'just a string', $sess )['error']['code'] );
        $this->assertSame( tronProtocol::METHOD_NOT_FOUND, $this->rpc( $s, $sess, 'nope/nothing' )['error']['code'] );
        $this->assertSame( tronProtocol::INVALID_PARAMS, $s->handle( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => 'x' ), $sess )['error']['code'] );
        $this->assertSame( null, $s->handle( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ), $sess ), 'notifications get no answer' );
        $this->assertSame( null, $s->handle( array( 'jsonrpc' => '2.0', 'id' => 9, 'result' => array() ), $sess ), 'client responses get no answer' );
        $this->assertSame( 'abc', $this->rpc( $s, $sess, 'ping', array(), 'abc' )['id'], 'string ids are kept' );
    }

    public function testBatchesFollowTheVersion(): void
    {
        $f = TronFixture::build();
        $batch = array( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping' ), array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ),
                        array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping' ) );
        $old = $f['server']->handle( $batch, TronFixture::session( TronFixture::ADMIN, 'http', '2025-03-26' ) );
        $this->assertCount( 2, $old, 'two answers, the notification has none' );
        $this->assertSame( 2, $old[1]['id'] );
        $new = $f['server']->handle( $batch, TronFixture::session( TronFixture::ADMIN, 'http', '2025-06-18' ) );
        $this->assertSame( tronProtocol::INVALID_REQUEST, $new['error']['code'] );
        $empty = $f['server']->handle( array(), TronFixture::session( TronFixture::ADMIN ) );
        $this->assertSame( tronProtocol::INVALID_REQUEST, $empty['error']['code'] );
    }

    public function testToolsListPaginates(): void
    {
        $f = TronFixture::build( array( 'settings' => array( 'ServerSettings' => array( 'PageSize' => 5 ) ) ) );
        $sess = TronFixture::session( TronFixture::ADMIN );
        $names = array();
        $cursor = null;
        $pages = 0;
        do
        {
            $r = $this->rpc( $f['server'], $sess, 'tools/list', $cursor ? array( 'cursor' => $cursor ) : array() );
            foreach ( $r['result']['tools'] as $t )
                $names[] = $t['name'];
            $cursor = $r['result']['nextCursor'] ?? null;
            $pages++;
        } while ( $cursor && $pages < 20 );
        $this->assertCount( 28, $names, 'tron 6 + skill 7 + centre 12 + katalyst 3' );
        $this->assertSame( 6, $pages );
        $sorted = $names;
        sort( $sorted );
        $this->assertSame( $sorted, $names );
        $this->assertSame( tronProtocol::INVALID_PARAMS, $this->rpc( $f['server'], $sess, 'tools/list', array( 'cursor' => 'garbage' ) )['error']['code'] );
    }

    public function testToolCallResults(): void
    {
        $f = TronFixture::build();
        $new = TronFixture::session( TronFixture::ADMIN, 'stdio', '2025-11-25' );
        $r = TronFixture::call( $f['server'], $new, 'tron_whoami' );
        $this->assertFalse( $r['result']['isError'] );
        $this->assertSame( 'admin', $r['result']['structuredContent']['login'] );
        $this->assertSame( 'admin', json_decode( $r['result']['content'][0]['text'], true )['login'] );
        $old = TronFixture::call( $f['server'], TronFixture::session( TronFixture::ADMIN, 'stdio', '2025-03-26' ), 'tron_whoami' );
        $this->assertFalse( isset( $old['result']['structuredContent'] ), 'no structuredContent before 2025-06-18' );

        $unknown = TronFixture::call( $f['server'], $new, 'nope_tool' );
        $this->assertSame( tronProtocol::INVALID_PARAMS, $unknown['error']['code'] );
        $this->assertContains( 'Unknown tool', $unknown['error']['message'] );

        $invalid = TronFixture::call( $f['server'], $new, 'centre_ticket_get', array( 'id' => 'x' ) );
        $this->assertTrue( $invalid['result']['isError'] );
        $this->assertContains( 'Invalid arguments', $invalid['result']['content'][0]['text'] );
        $this->assertContains( 'inputSchema', $invalid['result']['content'][0]['text'], 'the schema comes back so the model can correct itself' );

        $toolErr = TronFixture::call( $f['server'], $new, 'centre_ticket_get', array( 'id' => 999 ) );
        $this->assertTrue( $toolErr['result']['isError'] );
        $this->assertContains( 'There is no ticket 999', $toolErr['result']['content'][0]['text'] );
    }

    public function testAnswersAreCut(): void
    {
        $f = TronFixture::build( array( 'settings' => array( 'ServerSettings' => array( 'ResponseMaxBytes' => 300 ) ) ) );
        $sess = TronFixture::session( TronFixture::ADMIN );
        TronFixture::call( $f['server'], $sess, 'centre_ticket_create', array( 'title' => 'long', 'body' => str_repeat( 'b', 2000 ) ) );
        $r = TronFixture::call( $f['server'], $sess, 'centre_ticket_get', array( 'id' => 1 ) );
        $this->assertContains( 'the answer was cut at 300 bytes', $r['result']['content'][0]['text'] );
        $this->assertFalse( isset( $r['result']['structuredContent'] ) );
    }

    public function testInternalErrorsAreNotLeaked(): void
    {
        $logged = array();
        $f = TronFixture::build( array( 'logger' => function ( $l, $m ) use ( &$logged ) { $logged[] = "$l $m"; } ) );
        $f['db']->execute( 'DROP TABLE tron_centre_ticket' );
        $r = TronFixture::call( $f['server'], TronFixture::session( TronFixture::ADMIN ), 'centre_ticket_list' );
        $this->assertTrue( $r['result']['isError'] );
        $this->assertContains( 'internal error', $r['result']['content'][0]['text'] );
        $this->assertNotContains( 'no such table', $r['result']['content'][0]['text'], 'database details stay in the log' );
        $this->assertContains( 'no such table', implode( "\n", $logged ) );
        $last = end( $f['audit']->entries );
        $this->assertSame( 'error', $last['outcome'] );
    }

    public function testEveryCallIsAudited(): void
    {
        $f = TronFixture::build();
        $audit = $f['audit'];
        $sess = TronFixture::session( TronFixture::EDITOR, 'http', '2025-11-25' );
        TronFixture::call( $f['server'], $sess, 'centre_ticket_list' );
        $e = end( $audit->entries );
        $this->assertSame( 'tools/call', $e['method'] );
        $this->assertSame( 'centre_ticket_list', $e['target'] );
        $this->assertSame( 'centre', $e['program'] );
        $this->assertSame( 'ok', $e['outcome'] );
        $this->assertSame( TronFixture::EDITOR, $e['user_id'] );
        $this->assertSame( 'http', $e['transport'] );
        $this->assertSame( TronFixture::NOW, $e['created'] );

        $before = count( $audit->entries );
        TronFixture::call( $f['server'], $sess, 'centre_ticket_create', array( 'title' => 'x', 'body' => 'token tron_ABCDEFabcdef_' . str_repeat( 'Z', 43 ) ) );
        $new = array_slice( $audit->entries, $before );
        $this->assertSame( array( 'started', 'ok' ), array_column( $new, 'outcome' ), 'a write tool is audited before and after' );
        $this->assertContains( '"title":"x"', (string)( $new[0]['detail'] ?? '' ), 'the started row carries the redacted arguments' );
        $this->assertFalse( array_key_exists( 'args', $new[0] ), 'the started row has the same shape as every other row' );
        $this->assertNotContains( str_repeat( 'Z', 43 ), json_encode( $new ), 'tokens in arguments are masked' );

        TronFixture::call( $f['server'], $sess, 'centre_decision_record', array( 'id' => 1, 'decision' => 'x' ) );
        $this->assertSame( 'denied', end( $audit->entries )['outcome'] );
        TronFixture::call( $f['server'], $sess, 'centre_ticket_get', array( 'id' => 'x' ) );
        $this->assertSame( 'invalid', end( $audit->entries )['outcome'] );
        TronFixture::call( $f['server'], $sess, 'centre_ticket_get', array( 'id' => 77 ) );
        $this->assertSame( 'error', end( $audit->entries )['outcome'] );

        $this->rpc( $f['server'], $sess, 'resources/read', array( 'uri' => 'tron://status' ) );
        $this->assertSame( 'resources/read', end( $audit->entries )['method'] );
        $this->rpc( $f['server'], $sess, 'prompts/get', array( 'name' => 'centre_triage' ) );
        $this->assertSame( 'prompts/get', end( $audit->entries )['method'] );
    }

    public function testWriteToolsRefuseWithoutAudit(): void
    {
        $audit = new tronAuditMemory();
        $f = TronFixture::build( array( 'audit' => $audit ) );
        $audit->fail = true;
        $sess = TronFixture::session( TronFixture::ADMIN );
        $r = TronFixture::call( $f['server'], $sess, 'centre_ticket_create', array( 'title' => 'must not exist' ) );
        $this->assertTrue( $r['result']['isError'] );
        $this->assertContains( 'audit log is unavailable', $r['result']['content'][0]['text'] );
        $this->assertCount( 0, $f['db']->select( 'SELECT id FROM tron_centre_ticket' ), 'the write did not happen' );
        $read = TronFixture::call( $f['server'], $sess, 'centre_ticket_list' );
        $this->assertFalse( $read['result']['isError'], 'read tools still answer' );
    }

    public function testDatabaseAuditSink(): void
    {
        $db = tronDbPdo::sqliteMemory();
        $f = TronFixture::build( array( 'db' => $db, 'audit' => new tronAuditDb( $db ) ) );
        TronFixture::call( $f['server'], TronFixture::session( TronFixture::VIEWER ), 'centre_ticket_list', array( 'query' => 'x' ) );
        $rows = ( new tronAuditDb( $db ) )->recent( 10 );
        $this->assertCount( 1, $rows );
        $this->assertSame( 'viewer', $rows[0]['login'] );
        $this->assertSame( 'ok', $rows[0]['outcome'] );
        $this->assertContains( '"query":"x"', $rows[0]['detail'] );
        $this->assertCount( 0, ( new tronAuditDb( $db ) )->recent( 10, 0, TronFixture::ADMIN ) );
    }

    public function testFileAndMultiSinks(): void
    {
        $path = TronFixture::tmp( 'audit' ) . '/audit.jsonl';
        file_put_contents( $path, '' );
        $mem = new tronAuditMemory();
        $multi = new tronAuditMulti( array( new tronAuditFile( $path ), $mem ) );
        $multi->record( array( 'method' => 'x', 'outcome' => 'ok' ) );
        $lines = file( $path, FILE_IGNORE_NEW_LINES );
        $this->assertCount( 1, $lines );
        $this->assertSame( 'x', json_decode( $lines[0], true )['method'] );
        $this->assertCount( 1, $mem->entries );
        $mem->fail = true;
        $this->assertThrows( function () use ( $multi ) { $multi->record( array( 'method' => 'y' ) ); }, 'RuntimeException', 'audit' );
        $this->assertCount( 2, file( $path ), 'the healthy sink still kept the entry' );
    }
}
