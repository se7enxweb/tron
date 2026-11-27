<?php
/**
 * MCP conformance over the real transports, as a client sees them:
 *
 *   stdio: a child process (tests/fixtures/stdio_server.php) spoken to line by line. Lifecycle (nothing before
 *          initialize, version negotiation, initialized notification), ping, tools/list with pagination,
 *          tools/call (result, unknown tool, invalid arguments, tool error), resources and prompts, JSON-RPC errors
 *          (-32700, -32600, -32601, -32602, -32002), batches refused from 2025-06-18 on, an oversized line, stdout
 *          carrying nothing but JSON-RPC, a clean exit at end of input.
 *   HTTP:  PHP's built-in server (tests/fixtures/http_router.php): initialize, tools/list, tools/call with a token,
 *          202 for a notification, 401 without a token, 405 for GET, the audit row written by the server process.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronMcpConformanceTest extends TronTestCase
{
    /** @var resource|null */ private $proc;
    /** @var array */ private $pipes = array();
    /** @var string[] every line the server wrote to stdout */ private $stdout = array();

    private function start( array $env = array() ): void
    {
        $cmd = array( PHP_BINARY, '-d', 'display_errors=stderr', dirname( __DIR__ ) . '/fixtures/stdio_server.php' );
        $envAll = array_merge( getenv() ?: array(), $env );
        $this->proc = proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $this->pipes, null, $envAll );
        if ( !is_resource( $this->proc ) )
            $this->fail( 'could not start the stdio fixture' );
        stream_set_blocking( $this->pipes[1], false );
        stream_set_blocking( $this->pipes[2], false );
        $this->stdout = array();
    }

    private function send( $message ): void
    {
        fwrite( $this->pipes[0], ( is_string( $message ) ? $message : json_encode( $message ) ) . "\n" );
        fflush( $this->pipes[0] );
    }

    private function recv( float $timeout = 10.0 ): array
    {
        $buf = '';
        $end = microtime( true ) + $timeout;
        while ( microtime( true ) < $end )
        {
            $r = array( $this->pipes[1] );
            $w = $e = null;
            if ( @stream_select( $r, $w, $e, 0, 200000 ) > 0 )
            {
                $chunk = fgets( $this->pipes[1] );
                if ( $chunk !== false )
                {
                    $buf .= $chunk;
                    if ( substr( $buf, -1 ) === "\n" )
                    {
                        $this->stdout[] = rtrim( $buf, "\n" );
                        $d = json_decode( $buf, true );
                        if ( !is_array( $d ) )
                            $this->fail( 'stdout carried something that is not JSON: ' . substr( $buf, 0, 200 ) );
                        return $d;
                    }
                }
            }
        }
        $this->fail( 'no answer within ' . $timeout . ' s; stderr: ' . stream_get_contents( $this->pipes[2] ) );
        return array();
    }

    /** Sends a request and returns its answer. */
    private function rpc( string $method, ?array $params = null, $id = null ): array
    {
        static $n = 100;
        $m = array( 'jsonrpc' => '2.0', 'id' => $id ?? ++$n, 'method' => $method );
        if ( $params !== null )
            $m['params'] = $params === array() ? new stdClass() : $params;     // params is a JSON object, never a list
        $this->send( $m );
        $a = $this->recv();
        $this->assertSame( $m['id'], $a['id'] ?? null, "the answer to $method carries the request id" );
        $this->assertSame( '2.0', $a['jsonrpc'] );
        return $a;
    }

    /** Closes stdin and returns the exit code. */
    private function stop(): int
    {
        fclose( $this->pipes[0] );
        $end = microtime( true ) + 10;
        do
        {
            $st = proc_get_status( $this->proc );
            if ( !$st['running'] )
                break;
            usleep( 50000 );
        } while ( microtime( true ) < $end );
        $rest = stream_get_contents( $this->pipes[1] );
        if ( trim( (string)$rest ) !== '' )
            $this->fail( 'stdout carried more after the last answer: ' . substr( (string)$rest, 0, 200 ) );
        fclose( $this->pipes[1] );
        fclose( $this->pipes[2] );
        proc_close( $this->proc );
        return $st['running'] ? -1 : (int)$st['exitcode'];
    }

    public function testStdioLifecycleAndErrors(): void
    {
        $this->start( array( 'TRON_FIXTURE_PAGE' => '6' ) );

        $early = $this->rpc( 'tools/list' );
        $this->assertSame( tronProtocol::INVALID_REQUEST, $early['error']['code'], 'nothing but ping before initialize' );
        if ( self::sdk() )
            $this->assertSame( tronProtocol::INVALID_REQUEST, $this->rpc( 'ping' )['error']['code'], 'the SDK: not even ping before initialize' );
        else
            $this->assertSame( array(), (array)$this->rpc( 'ping' )['result'] );

        $init = $this->rpc( 'initialize', array( 'protocolVersion' => '2099-01-01', 'capabilities' => new stdClass(),
                                                 'clientInfo' => array( 'name' => 'conformance', 'version' => '1' ) ) );
        $this->assertSame( tronProtocol::LATEST, $init['result']['protocolVersion'], 'an unknown version is answered with the newest' );
        $this->assertSame( 'tron', $init['result']['serverInfo']['name'] );
        $this->assertTrue( isset( $init['result']['capabilities']['tools'] ) && isset( $init['result']['capabilities']['resources'] ) && isset( $init['result']['capabilities']['prompts'] ) );
        $this->assertTrue( is_string( $init['result']['instructions'] ) );
        $this->send( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) );

        // tools/list, all pages
        $names = array();
        $cursor = null;
        do
        {
            $page = $this->rpc( 'tools/list', $cursor ? array( 'cursor' => $cursor ) : array() );
            foreach ( $page['result']['tools'] as $t )
            {
                $names[] = $t['name'];
                $this->assertSame( 'object', $t['inputSchema']['type'], $t['name'] );
                $this->assertTrue( isset( $t['description'] ) && isset( $t['annotations']['readOnlyHint'] ), $t['name'] );
            }
            $cursor = $page['result']['nextCursor'] ?? null;
        } while ( $cursor );
        $this->assertCount( 28, $names, 'tron 6 + skill 7 + centre 12 + katalyst 3' );
        $this->assertContains( 'centre_ticket_create', $names );

        // tools/call
        $created = $this->rpc( 'tools/call', array( 'name' => 'centre_ticket_create', 'arguments' => array( 'title' => 'over stdio' ) ) );
        $this->assertFalse( $created['result']['isError'] );
        $this->assertSame( 'text', $created['result']['content'][0]['type'] );
        $this->assertSame( 'over stdio', $created['result']['structuredContent']['title'] );
        $unknown = $this->rpc( 'tools/call', array( 'name' => 'no_such_tool', 'arguments' => array() ) );
        $this->assertSame( tronProtocol::INVALID_PARAMS, $unknown['error']['code'] );
        $invalid = $this->rpc( 'tools/call', array( 'name' => 'centre_ticket_get', 'arguments' => array( 'id' => -1 ) ) );
        $this->assertTrue( $invalid['result']['isError'], 'invalid arguments are a tool error the model can correct' );
        $missing = $this->rpc( 'tools/call', array( 'name' => 'centre_ticket_get', 'arguments' => array( 'id' => 404 ) ) );
        $this->assertTrue( $missing['result']['isError'] );
        $this->assertSame( tronProtocol::INVALID_PARAMS, $this->rpc( 'tools/call', array( 'arguments' => array() ) )['error']['code'], 'no tool name' );

        // resources and prompts
        $res = $this->rpc( 'resources/list' );
        $this->assertSame( array( 'tron://programs', 'tron://rules/agents', 'tron://rules/combined', 'tron://rules/master', 'tron://rules/status', 'tron://status' ), array_column( $res['result']['resources'], 'uri' ) );
        $tpl = $this->rpc( 'resources/templates/list' );
        $this->assertCount( 2, $tpl['result']['resourceTemplates'] );
        $read = $this->rpc( 'resources/read', array( 'uri' => 'centre://ticket/1' ) );
        $this->assertSame( 'centre://ticket/1', $read['result']['contents'][0]['uri'] );
        $this->assertSame( tronProtocol::RESOURCE_NOT_FOUND, $this->rpc( 'resources/read', array( 'uri' => 'nowhere://x' ) )['error']['code'] );
        $this->assertSame( tronProtocol::INVALID_PARAMS, $this->rpc( 'resources/read', array() )['error']['code'] );
        $prompts = $this->rpc( 'prompts/list' );
        $this->assertCount( 4, $prompts['result']['prompts'] );
        $prompt = $this->rpc( 'prompts/get', array( 'name' => 'centre_weekly_review' ) );
        $this->assertSame( 'text', $prompt['result']['messages'][0]['content']['type'] );

        // JSON-RPC errors
        $this->send( '{"jsonrpc":"2.0","id":5,"method":' );
        $parse = $this->recv();
        $this->assertSame( tronProtocol::PARSE_ERROR, $parse['error']['code'] );
        $this->assertSame( null, $parse['id'] ?? null );
        $this->send( array( 'id' => 6, 'method' => 'ping' ) );
        $this->assertSame( tronProtocol::INVALID_REQUEST, $this->recv()['error']['code'] );
        // a method only clients answer: the legacy layer says -32601; the SDK knows the method and refuses its params (-32602)
        $this->assertSame( self::sdk() ? tronProtocol::INVALID_PARAMS : tronProtocol::METHOD_NOT_FOUND, $this->rpc( 'sampling/createMessage' )['error']['code'], 'a method servers do not have' );
        $this->send( array( array( 'jsonrpc' => '2.0', 'id' => 7, 'method' => 'ping' ) ) );
        $this->assertSame( tronProtocol::INVALID_REQUEST, $this->recv()['error']['code'], 'no batches in 2025-11-25' );
        $this->send( str_repeat( 'x', 70000 ) );
        // an oversized line is refused: the legacy layer answers a parse error, the SDK drops the line and logs it
        if ( !self::sdk() )
            $this->assertSame( tronProtocol::PARSE_ERROR, $this->recv()['error']['code'], 'an oversized line is refused' );
        $this->assertSame( array(), (array)$this->rpc( 'ping' )['result'], 'and the server carries on' );

        // notifications and stray responses get no answer: the next answer is the ping's
        $this->send( array( 'jsonrpc' => '2.0', 'method' => 'notifications/cancelled', 'params' => array( 'requestId' => 1 ) ) );
        $this->send( array( 'jsonrpc' => '2.0', 'id' => 'x', 'result' => new stdClass() ) );
        $this->assertSame( 'last', $this->rpc( 'ping', null, 'last' )['id'] );

        $this->assertSame( 0, $this->stop(), 'clean exit at end of input' );
        foreach ( $this->stdout as $line )
            $this->assertTrue( is_array( json_decode( $line, true ) ), 'every stdout line is JSON' );
    }

    public function testStdioNegotiatesEachVersion(): void
    {
        foreach ( tronProtocol::VERSIONS as $v )
        {
            $this->start();
            $init = $this->rpc( 'initialize', array( 'protocolVersion' => $v, 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 'c', 'version' => '1' ) ) );
            $this->assertSame( $v, $init['result']['protocolVersion'] );
            $this->send( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) );
            $call = $this->rpc( 'tools/call', array( 'name' => 'tron_whoami', 'arguments' => new stdClass() ) );
            $this->assertSame( strcmp( $v, '2025-06-18' ) >= 0, isset( $call['result']['structuredContent'] ), "structuredContent in $v" );
            $this->assertSame( 0, $this->stop() );
        }
    }

    public function testStdioPermissionsOfTheProcessUser(): void
    {
        $this->start( array( 'TRON_FIXTURE_USER' => (string)TronFixture::VIEWER ) );
        $this->rpc( 'initialize', array( 'protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 'c', 'version' => '1' ) ) );
        $names = array_column( $this->rpc( 'tools/list' )['result']['tools'], 'name' );
        $this->assertNotContains( 'centre_ticket_create', $names );
        $denied = $this->rpc( 'tools/call', array( 'name' => 'centre_ticket_create', 'arguments' => array( 'title' => 'x' ) ) );
        $this->assertTrue( $denied['result']['isError'] );
        $this->assertContains( 'Permission denied', $denied['result']['content'][0]['text'] );
        $this->assertSame( 0, $this->stop() );
    }

    // ------------------------------------------------------------------ HTTP

    private function http( int $port, string $method, string $body, array $headers ): array
    {
        $s = @stream_socket_client( "tcp://127.0.0.1:$port", $errno, $errstr, 5 );
        if ( !$s )
            $this->fail( "cannot connect to 127.0.0.1:$port: $errstr" );
        $req = "$method /mcp HTTP/1.0\r\nHost: 127.0.0.1\r\nConnection: close\r\nContent-Length: " . strlen( $body ) . "\r\n";
        foreach ( $headers as $k => $v )
            $req .= "$k: $v\r\n";
        fwrite( $s, $req . "\r\n" . $body );
        stream_set_timeout( $s, 10 );
        $raw = stream_get_contents( $s );
        fclose( $s );
        list( $head, $payload ) = array_pad( explode( "\r\n\r\n", (string)$raw, 2 ), 2, '' );
        $lines = explode( "\r\n", $head );
        preg_match( '#^HTTP/\S+ (\d{3})#', $lines[0], $m );
        $h = array();
        foreach ( array_slice( $lines, 1 ) as $l )
        {
            $p = strpos( $l, ':' );
            if ( $p )
                $h[strtolower( substr( $l, 0, $p ) )] = trim( substr( $l, $p + 1 ) );
        }
        return array( (int)( $m[1] ?? 0 ), $h, $payload );
    }

    public function testHttpEndToEnd(): void
    {
        $dbFile = TronFixture::tmp( 'http' ) . '/tron.sqlite';
        if ( is_file( $dbFile ) )
            file_put_contents( $dbFile, '' );
        $db = new tronDbPdo( new PDO( 'sqlite:' . $dbFile ) );
        tronSchema::install( $db );
        $tokens = new tronTokenService( $db, new tronArrayUserDirectory( TronFixture::users() ), function () { return TronFixture::NOW; } );
        $token = $tokens->create( TronFixture::EDITOR, 'conformance', 5 )['token'];

        $probe = stream_socket_server( 'tcp://127.0.0.1:0' );
        $port = (int)substr( (string)stream_socket_get_name( $probe, false ), strrpos( (string)stream_socket_get_name( $probe, false ), ':' ) + 1 );
        fclose( $probe );
        $env = array_merge( getenv() ?: array(), array( 'TRON_FIXTURE_DB' => $dbFile ) );
        $proc = proc_open( array( PHP_BINARY, '-S', "127.0.0.1:$port", dirname( __DIR__ ) . '/fixtures/http_router.php' ),
                           array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', TronFixture::tmp( 'http' ) . '/server.out', 'w' ), 2 => array( 'file', TronFixture::tmp( 'http' ) . '/server.err', 'w' ) ),
                           $pipes, null, $env );
        try
        {
            $up = false;
            for ( $i = 0; $i < 100 && !$up; $i++ )
            {
                $c = @stream_socket_client( "tcp://127.0.0.1:$port", $e1, $e2, 0.2 );
                if ( $c )
                {
                    fclose( $c );
                    $up = true;
                }
                else
                    usleep( 50000 );
            }
            $this->assertTrue( $up, 'the built-in server came up' );
            $json = array( 'Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream' );
            $auth = $json + array( 'Authorization' => "Bearer $token" );

            list( $st, $h, $b ) = $this->http( $port, 'POST', json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize',
                'params' => array( 'protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 'c', 'version' => '1' ) ) ) ), $auth );
            $this->assertSame( 200, $st, "initialize: $b" );
            $this->assertContains( 'application/json', $h['content-type'] );
            $this->assertSame( '2025-06-18', json_decode( $b, true )['result']['protocolVersion'] );

            $v = $auth + array( 'MCP-Protocol-Version' => '2025-06-18' );
            list( $st, , $b ) = $this->http( $port, 'POST', json_encode( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) ), $v );
            $this->assertSame( 202, $st );
            $this->assertSame( '', $b );

            list( $st, , $b ) = $this->http( $port, 'POST', json_encode( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list' ) ), $v );
            $this->assertSame( 200, $st );
            $this->assertContains( 'centre_ticket_create', array_column( json_decode( $b, true )['result']['tools'], 'name' ) );

            list( $st, , $b ) = $this->http( $port, 'POST', json_encode( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call',
                'params' => array( 'name' => 'centre_ticket_create', 'arguments' => array( 'title' => 'over http' ) ) ) ), $v );
            $this->assertSame( 200, $st );
            $d = json_decode( $b, true );
            $this->assertSame( 'over http', $d['result']['structuredContent']['title'] );
            $this->assertSame( 'editor', $d['result']['structuredContent']['created_by'] );

            list( $st, $h ) = $this->http( $port, 'POST', json_encode( array( 'jsonrpc' => '2.0', 'id' => 4, 'method' => 'ping' ) ), $json );
            $this->assertSame( 401, $st );
            $this->assertSame( 'Bearer realm="tron"', $h['www-authenticate'] ?? '' );
            list( $st ) = $this->http( $port, 'GET', '', $auth );
            $this->assertSame( 405, $st );

            $rows = $db->select( "SELECT method, target, outcome, login FROM tron_audit ORDER BY id" );
            $this->assertContains( 'centre_ticket_create', array_column( $rows, 'target' ) );
            $this->assertSame( array( 'started', 'ok' ), array_values( array_column( array_filter( $rows, function ( $r ) { return $r['target'] === 'centre_ticket_create'; } ), 'outcome' ) ) );
            $this->assertSame( 'unauthenticated', end( $rows )['outcome'] );
            $this->assertSame( 1, count( $db->select( 'SELECT id FROM tron_centre_ticket' ) ), 'the ticket is in the database the server wrote to' );
        }
        finally
        {
            proc_terminate( $proc );
            proc_close( $proc );
        }
    }
}
