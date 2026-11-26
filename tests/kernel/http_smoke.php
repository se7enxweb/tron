<?php
/**
 * Speaks MCP over HTTP to a real tron endpoint and checks the answers and every refusal status.
 *
 *   php <tron>/tests/kernel/http_smoke.php --url=https://<host>/tron/mcp --token-file=<0600 file> \
 *       [--resolve=<host>:<port>:<address>] [--insecure] [--write] [--expect-tools=<n>]
 *
 * --resolve sends the request to one server (a node, a Velocity port) with the right Host and SNI, past the load
 * balancer. Read-only by default; --write also creates and closes one ticket. Never prints the token.
 * Exit 0 PASS, 1 FAIL. Needs the curl extension.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

$o = getopt( '', array( 'url:', 'token-file:', 'resolve:', 'insecure', 'write', 'expect-tools:' ) );
if ( empty( $o['url'] ) || empty( $o['token-file'] ) || !function_exists( 'curl_init' ) )
{
    fwrite( STDERR, "usage: http_smoke.php --url=<endpoint> --token-file=<file> [--resolve=h:p:a] [--insecure] [--write] (needs ext-curl)\n" );
    exit( 2 );
}
$url = $o['url'];
$token = trim( (string)@file_get_contents( $o['token-file'] ) );
$fails = 0;
$times = array();
$check = function ( bool $ok, string $what ) use ( &$fails ) {
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $what . "\n";
    if ( !$ok )
        $fails++;
};
$req = function ( string $method, string $body, array $headers ) use ( $url, $o, &$times ) {
    $h = array();
    foreach ( $headers as $k => $v )
        $h[] = "$k: $v";
    $c = curl_init( $url );
    curl_setopt_array( $c, array(
        CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_HTTPHEADER => $h,
        CURLOPT_TIMEOUT => 30, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_FOLLOWLOCATION => false,
    ) );
    if ( $method === 'POST' )
        curl_setopt( $c, CURLOPT_POSTFIELDS, $body );
    if ( !empty( $o['resolve'] ) )
        curl_setopt( $c, CURLOPT_RESOLVE, array( $o['resolve'] ) );
    if ( isset( $o['insecure'] ) )
    {
        curl_setopt( $c, CURLOPT_SSL_VERIFYPEER, false );
        curl_setopt( $c, CURLOPT_SSL_VERIFYHOST, 0 );
    }
    $t = microtime( true );
    $raw = curl_exec( $c );
    $times[] = ( microtime( true ) - $t ) * 1000;
    if ( $raw === false )
        return array( 0, array(), 'curl: ' . curl_error( $c ) );
    $hs = curl_getinfo( $c, CURLINFO_HEADER_SIZE );
    $status = (int)curl_getinfo( $c, CURLINFO_RESPONSE_CODE );
    $head = array();
    foreach ( explode( "\r\n", substr( $raw, 0, $hs ) ) as $l )
    {
        $p = strpos( $l, ':' );
        if ( $p )
            $head[strtolower( substr( $l, 0, $p ) )] = trim( substr( $l, $p + 1 ) );
    }
    return array( $status, $head, (string)substr( $raw, $hs ) );
};
$json = array( 'Content-Type' => 'application/json', 'Accept' => 'application/json, text/event-stream' );
$auth = $json + array( 'Authorization' => "Bearer $token" );
$v = $auth + array( 'MCP-Protocol-Version' => '2025-06-18' );
$call = function ( string $method, array $params, array $headers ) use ( $req ) {
    static $id = 0;
    list( $s, $h, $b ) = $req( 'POST', json_encode( array( 'jsonrpc' => '2.0', 'id' => ++$id, 'method' => $method, 'params' => (object)$params ) ), $headers );
    return array( $s, $h, json_decode( $b, true ), $b );
};

list( $s, $h, $d, $raw ) = $call( 'initialize', array( 'protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 'tron-http-smoke', 'version' => '1' ) ), $auth );
$check( $s === 200 && ( $d['result']['serverInfo']['name'] ?? '' ) === 'tron', "initialize: $s" . ( $s === 200 ? '' : ' ' . substr( preg_replace( '/\s+/', ' ', strip_tags( $raw ) ), 0, 160 ) ) );
$check( strpos( (string)( $h['content-type'] ?? '' ), 'application/json' ) === 0, 'initialize: Content-Type application/json' );
$check( !isset( $h['mcp-session-id'] ), 'stateless: no Mcp-Session-Id' );
list( $s, , $b ) = $req( 'POST', json_encode( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) ), $v );
$check( $s === 202 && $b === '', "notification: 202 without a body ($s)" );
list( $s, , $d ) = $call( 'tools/list', array(), $v );
$names = array_column( $d['result']['tools'] ?? array(), 'name' );
$check( $s === 200 && $names, 'tools/list: ' . count( $names ) . ' tools' );
if ( isset( $o['expect-tools'] ) )
    $check( count( $names ) === (int)$o['expect-tools'], 'tools/list: the expected ' . (int)$o['expect-tools'] . ' tools for this token\'s user' );
list( $s, , $d ) = $call( 'tools/call', array( 'name' => 'tron_whoami', 'arguments' => new stdClass() ), $v );
$who = $d['result']['structuredContent'] ?? array();
$check( $s === 200 && ( $who['transport'] ?? '' ) === 'http' && (int)( $who['token_id'] ?? 0 ) > 0, 'tools/call tron_whoami: ' . ( $who['login'] ?? '?' ) . ' over http with a token' );
if ( in_array( 'centre_ticket_list', $names, true ) )
{
    list( $s, , $d ) = $call( 'tools/call', array( 'name' => 'centre_ticket_list', 'arguments' => array( 'limit' => 3 ) ), $v );
    $check( $s === 200 && empty( $d['result']['isError'] ), 'tools/call centre_ticket_list' );
}
if ( isset( $o['write'] ) && in_array( 'centre_ticket_create', $names, true ) )
{
    list( $s, , $d ) = $call( 'tools/call', array( 'name' => 'centre_ticket_create', 'arguments' => array( 'title' => 'tron http smoke ' . gmdate( 'H:i:s' ), 'labels' => array( 'tron-smoke' ) ) ), $v );
    $id = (int)( $d['result']['structuredContent']['id'] ?? 0 );
    $check( $id > 0, 'tools/call centre_ticket_create over http' );
    list( $s, , $d ) = $call( 'tools/call', array( 'name' => 'centre_ticket_close', 'arguments' => array( 'id' => $id, 'reason' => 'http smoke' ) ), $v );
    $check( ( $d['result']['structuredContent']['status'] ?? '' ) === 'closed', 'tools/call centre_ticket_close over http' );
}
else if ( isset( $o['write'] ) )
{
    list( $s, , $d ) = $call( 'tools/call', array( 'name' => 'centre_ticket_create', 'arguments' => array( 'title' => 'must be refused' ) ), $v );
    $check( !empty( $d['result']['isError'] ) && strpos( (string)( $d['result']['content'][0]['text'] ?? '' ), 'Permission denied' ) !== false, 'a write tool without the policy is refused over http' );
}

// refusals
list( $s, $h ) = $call( 'ping', array(), $json );
$check( $s === 401 && ( $h['www-authenticate'] ?? '' ) === 'Bearer realm="tron"', "no token: 401 with WWW-Authenticate ($s)" );
list( $s ) = $call( 'ping', array(), $json + array( 'Authorization' => 'Bearer tron_AAAAAAAAAAAA_' . str_repeat( 'b', 43 ) ) );
$check( $s === 401, "unknown token: 401 ($s)" );
list( $s ) = $req( 'GET', '', $auth );
$check( $s === 405, "GET: 405 ($s)" );
list( $s ) = $call( 'ping', array(), $auth + array( 'Origin' => 'https://evil.example' ) );
$check( $s === 403, "foreign Origin: 403 ($s)" );
list( $s ) = $req( 'POST', '{"jsonrpc":"2.0","id":1,"method":"ping"}', array( 'Content-Type' => 'text/plain', 'Authorization' => "Bearer $token" ) );
$check( $s === 415, "text/plain: 415 ($s)" );
list( $s ) = $call( 'ping', array(), $auth + array( 'MCP-Protocol-Version' => '1999-01-01' ) );
$check( $s === 400, "unknown MCP-Protocol-Version: 400 ($s)" );
list( $s, , $d ) = $call( 'ping', array(), $auth );
$check( $s === 200 && isset( $d['result'] ), 'ping: 200' );

sort( $times );
printf( "     %d requests, median %.1f ms, max %.1f ms\n", count( $times ), $times[(int)floor( ( count( $times ) - 1 ) / 2 )], end( $times ) );
echo ( $fails ? 'FAIL' : 'PASS' ) . ": http smoke, $fails failed\n";
exit( $fails ? 1 : 0 );
