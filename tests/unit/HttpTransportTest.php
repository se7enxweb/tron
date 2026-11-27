<?php
/**
 * The HTTP transport in process: every refusal with its status, the token, and the stateless request rules.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronHttpTransportTest extends TronTestCase
{
    /** @var array */ private $f;
    /** @var tronHttpTransport */ private $http;
    /** @var string */ private $token;

    public function setUp(): void
    {
        $this->f = TronFixture::build();
        $this->token = $this->f['tokens']->create( TronFixture::EDITOR, 'http test', 5 )['token'];
        $this->http = $this->transport( array( 'allowedOrigins' => array( 'https://centre.se7enx.com' ), 'allowedHosts' => array( 'centre.se7enx.com' ), 'maxBodyBytes' => 2048 ) );
    }

    private function transport( array $options ): tronHttpTransport
    {
        $tokens = $this->f['tokens'];
        return new tronHttpTransport( $this->f['server'], function ( string $t, ?string &$reason = null ) use ( $tokens ) {
            return $tokens->authenticate( $t, $reason );
        }, TronFixture::access(), $this->f['audit'], $options, function () { return TronFixture::NOW; } );
    }

    private function req( $body, array $headers = array(), string $method = 'POST' ): tronHttpResponse
    {
        $h = $headers + array( 'Host' => 'centre.se7enx.com', 'Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream',
                               'Authorization' => 'Bearer ' . $this->token );
        return $this->http->handle( new tronHttpRequest( $method, $h, is_string( $body ) ? $body : json_encode( $body ), '198.51.100.7' ) );
    }

    public function testInitializeAndCall(): void
    {
        $r = $this->req( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array( 'protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 't', 'version' => '1' ) ) ) );
        $this->assertSame( 200, $r->status );
        $this->assertSame( 0, strpos( $r->headers['Content-Type'], 'application/json' ) );
        $this->assertSame( 'no-store', $r->headers['Cache-Control'] );
        $this->assertFalse( isset( $r->headers['Mcp-Session-Id'] ), 'stateless: no session id' );
        $this->assertSame( '2025-06-18', json_decode( $r->body, true )['result']['protocolVersion'] );

        $c = $this->req( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => array( 'name' => 'tron_whoami' ) ), array( 'MCP-Protocol-Version' => '2025-06-18' ) );
        $d = json_decode( $c->body, true );
        $this->assertSame( 'editor', $d['result']['structuredContent']['login'] );
        $this->assertSame( 'http', $d['result']['structuredContent']['transport'] );
        $this->assertTrue( $d['result']['structuredContent']['token_id'] > 0 );
        $e = end( $this->f['audit']->entries );
        $this->assertSame( '198.51.100.7', $e['remote'] );
        $this->assertSame( 'http', $e['transport'] );

        $note = $this->req( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) );
        $this->assertSame( 202, $note->status );
        $this->assertSame( '', $note->body );
    }

    public function testNoHeaderMeansTheDefaultVersion(): void
    {
        $batch = array( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping' ), array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping' ) );
        $r = $this->req( $batch );
        $this->assertSame( 2, count( json_decode( $r->body, true ) ), 'without MCP-Protocol-Version: 2025-03-26, batches allowed' );
        $r2 = $this->req( $batch, array( 'MCP-Protocol-Version' => '2025-11-25' ) );
        $this->assertSame( tronProtocol::INVALID_REQUEST, json_decode( $r2->body, true )['error']['code'] );
        $w = $this->req( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => array( 'name' => 'tron_whoami' ) ) );
        $this->assertFalse( isset( json_decode( $w->body, true )['result']['structuredContent'] ) );
    }

    public function testRefusals(): void
    {
        $ping = array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping' );
        $get = $this->req( '', array(), 'GET' );
        $this->assertSame( 405, $get->status );
        $this->assertSame( 'POST', $get->headers['Allow'] );
        $this->assertSame( 405, $this->req( '', array(), 'DELETE' )->status );
        $this->assertSame( 204, $this->req( '', array(), 'OPTIONS' )->status );
        $this->assertSame( 403, $this->req( $ping, array( 'Origin' => 'https://evil.example' ) )->status );
        $this->assertSame( 200, $this->req( $ping, array( 'Origin' => 'https://centre.se7enx.com' ) )->status );
        $this->assertSame( 421, $this->req( $ping, array( 'Host' => 'other.example' ) )->status );
        $this->assertSame( 200, $this->req( $ping, array( 'Host' => 'centre.se7enx.com:443' ) )->status );
        $this->assertSame( 415, $this->req( $ping, array( 'Content-Type' => 'text/plain' ) )->status );
        $this->assertSame( 200, $this->req( $ping, array( 'Content-Type' => 'application/json; charset=utf-8' ) )->status );
        $this->assertSame( 413, $this->req( str_repeat( ' ', 3000 ) . json_encode( $ping ) )->status );
        $bad = $this->req( $ping, array( 'MCP-Protocol-Version' => '2020-01-01' ) );
        $this->assertSame( 400, $bad->status );
        $this->assertContains( 'unsupported MCP-Protocol-Version', $bad->body );

        $none = $this->req( $ping, array( 'Authorization' => '' ) );
        $this->assertSame( 401, $none->status );
        $this->assertSame( 'Bearer realm="tron"', $none->headers['WWW-Authenticate'] );
        $this->assertSame( 401, $this->req( $ping, array( 'Authorization' => 'Basic abc' ) )->status );
        $this->assertSame( 401, $this->req( $ping, array( 'Authorization' => 'Bearer tron_AAAAAAAAAAAA_' . str_repeat( 'b', 43 ) ) )->status );
        $e = end( $this->f['audit']->entries );
        $this->assertSame( 'auth', $e['method'] );
        $this->assertSame( 'unauthenticated', $e['outcome'] );
        $this->assertContains( 'unknown token AAAAAAAAAAAA', $e['detail'] );
        $this->assertNotContains( str_repeat( 'b', 43 ), json_encode( $this->f['audit']->entries ), 'the token itself is never logged' );
    }

    public function testUsersWithoutTronUseAreRefused(): void
    {
        $t = $this->f['tokens']->create( TronFixture::NOROLE, 'x', 5 )['token'];
        $r = $this->req( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping' ), array( 'Authorization' => 'Bearer ' . $t ) );
        $this->assertSame( 403, $r->status );
        $this->assertContains( 'tron/use', $r->body );
        $this->assertSame( 'denied', end( $this->f['audit']->entries )['outcome'] );
    }

    public function testRevokedTokensStopAtOnce(): void
    {
        $ping = array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping' );
        $this->assertSame( 200, $this->req( $ping )->status );
        $this->f['tokens']->revoke( substr( $this->token, 5, 12 ) );
        $this->assertSame( 401, $this->req( $ping )->status );
    }

    public function testScopedTokens(): void
    {
        $t = $this->f['tokens']->create( TronFixture::ADMIN, 'x', 5, array( 'centre' ) )['token'];
        $r = $this->req( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ), array( 'Authorization' => 'Bearer ' . $t ) );
        $names = array_column( json_decode( $r->body, true )['result']['tools'], 'name' );
        $this->assertContains( 'centre_ticket_list', $names );
        $this->assertNotContains( 'katalyst_orders_list', $names );
    }

    public function testOpenHostsAndNoOriginsByDefault(): void
    {
        $this->http = $this->transport( array() );
        $ping = array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping' );
        $this->assertSame( 200, $this->req( $ping, array( 'Host' => '10.200.0.5' ) )->status, 'no host list: any host (mesh address)' );
        $this->assertSame( 403, $this->req( $ping, array( 'Origin' => 'https://centre.se7enx.com' ) )->status, 'no origin list: browsers refused' );
    }

    public function testFromGlobals(): void
    {
        $saved = $_SERVER;
        $_SERVER = array( 'REQUEST_METHOD' => 'POST', 'HTTP_HOST' => 'centre.se7enx.com', 'CONTENT_TYPE' => 'application/json',
                          'REDIRECT_HTTP_AUTHORIZATION' => 'Bearer x', 'HTTP_MCP_PROTOCOL_VERSION' => '2025-06-18', 'REMOTE_ADDR' => '203.0.113.9' );
        $r = tronHttpRequest::fromGlobals( 1024 );
        $_SERVER = $saved;
        $this->assertSame( 'POST', $r->method );
        $this->assertSame( 'centre.se7enx.com', $r->header( 'Host' ) );
        $this->assertSame( 'Bearer x', $r->header( 'authorization' ), 'Apache + FPM move it to REDIRECT_HTTP_AUTHORIZATION' );
        $this->assertSame( '2025-06-18', $r->header( 'mcp-protocol-version' ) );
        $this->assertSame( 'application/json', $r->header( 'content-type' ) );
        $this->assertSame( '203.0.113.9', $r->remote );
    }
}
