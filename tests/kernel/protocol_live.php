<?php
/**
 * Proves the protocol layer of a real installation over stdio or HTTP, as a client sees it: initialize, tools/list
 * (every page), a read tool, a write tool the user's policies refuse, and a write tool they allow, whose audit entry
 * is written before it runs.
 *
 *   stdio: php <tron>/tests/kernel/protocol_live.php --deny=<write tool> -- <tron.php serve command with TRON_TOKEN set>
 *   HTTP:  php <tron>/tests/kernel/protocol_live.php --deny=<write tool> --url=<endpoint> --token-file=<0600 file> [--host=<Host>]
 *
 * --deny names a write tool the token's user lacks the policy for (e.g. centre_decision_record for an editor). The
 * allowed write is skill_draft with a manifest that names no steps: tron/use allows it, the "started" audit entry is
 * written, then the skill standard refuses the manifest, so nothing is stored. Read the entries afterwards with
 * tron.php audit:tail --user=<login>. Prints the layer the server reports (tron_status). Never prints the token.
 * Exit 0 PASS, 1 FAIL, 2 usage.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

$sep = array_search( '--', $argv, true );
$opts = getopt( '', array( 'deny:', 'url:', 'token-file:', 'host:' ) );
$cmd = $sep === false ? array() : array_slice( $argv, $sep + 1 );
$deny = (string)( $opts['deny'] ?? '' );
if ( $deny === '' || ( !$cmd && empty( $opts['url'] ) ) )
{
    fwrite( STDERR, "usage: protocol_live.php --deny=<tool> (-- <serve command> | --url=<endpoint> --token-file=<file> [--host=<Host>])\n" );
    exit( 2 );
}

$fails = 0;
$check = function ( bool $ok, string $what ) use ( &$fails ) {
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $what . "\n";
    if ( !$ok )
        $fails++;
};

if ( $cmd )
{
    $proc = proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
    if ( !is_resource( $proc ) )
        exit( 2 );
    $transport = 'stdio';
    $rpc = function ( array $m ) use ( $pipes ) {
        fwrite( $pipes[0], json_encode( $m ) . "\n" );
        fflush( $pipes[0] );
        if ( !isset( $m['id'] ) )
            return null;
        $line = fgets( $pipes[1] );
        return $line === false ? array( 'error' => array( 'message' => 'no answer; stderr: ' . substr( (string)stream_get_contents( $pipes[2] ), 0, 300 ) ) ) : json_decode( $line, true );
    };
}
else
{
    $token = trim( (string)@file_get_contents( (string)$opts['token-file'] ) );
    $transport = 'http';
    $version = null;
    $rpc = function ( array $m ) use ( $opts, $token, &$version ) {
        $h = array( 'Content-Type: application/json', 'Accept: application/json, text/event-stream', "Authorization: Bearer $token" );
        if ( $version !== null )
            $h[] = "MCP-Protocol-Version: $version";
        if ( !empty( $opts['host'] ) )
            $h[] = 'Host: ' . $opts['host'];
        $c = curl_init( (string)$opts['url'] );
        curl_setopt_array( $c, array( CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode( $m ), CURLOPT_HTTPHEADER => $h,
                                      CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60 ) );
        $body = (string)curl_exec( $c );
        $status = (int)curl_getinfo( $c, CURLINFO_RESPONSE_CODE );
        if ( !isset( $m['id'] ) )
            return array( 'status' => $status );
        $d = json_decode( $body, true );
        return is_array( $d ) ? $d : array( 'error' => array( 'message' => "HTTP $status: " . substr( $body, 0, 300 ) ) );
    };
}

$id = 0;
$call = function ( string $tool, $args ) use ( $rpc, &$id ) {
    return $rpc( array( 'jsonrpc' => '2.0', 'id' => ++$id, 'method' => 'tools/call', 'params' => array( 'name' => $tool, 'arguments' => $args ) ) );
};
$text = function ( array $r ) {
    return (string)( $r['result']['content'][0]['text'] ?? json_encode( $r['error'] ?? $r ) );
};

$init = $rpc( array( 'jsonrpc' => '2.0', 'id' => ++$id, 'method' => 'initialize', 'params' => array(
    'protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 'tron-protocol-live', 'version' => '1' ) ) ) );
$check( ( $init['result']['serverInfo']['name'] ?? '' ) === 'tron' && ( $init['result']['protocolVersion'] ?? '' ) === '2025-11-25',
        "$transport initialize: " . ( $init['result']['protocolVersion'] ?? $text( $init ) ) );
if ( $transport === 'http' )
    $version = '2025-11-25';
$note = $rpc( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) );
if ( $transport === 'http' )
    $check( ( $note['status'] ?? 0 ) === 202, "$transport notification: 202 (" . ( $note['status'] ?? 0 ) . ')' );

$names = array();
$cursor = null;
$pages = 0;
do
{
    $l = $rpc( array( 'jsonrpc' => '2.0', 'id' => ++$id, 'method' => 'tools/list', 'params' => $cursor ? array( 'cursor' => $cursor ) : new stdClass() ) );
    foreach ( (array)( $l['result']['tools'] ?? array() ) as $t )
        $names[] = $t['name'];
    $cursor = $l['result']['nextCursor'] ?? null;
    $pages++;
} while ( $cursor && $pages < 50 );
$check( count( $names ) > 0 && in_array( 'tron_whoami', $names, true ), "$transport tools/list: " . count( $names ) . " tools in $pages page(s)" );
$check( !in_array( $deny, $names, true ), "$transport tools/list leaves out $deny" );

$who = $call( 'tron_whoami', new stdClass() );
$check( empty( $who['result']['isError'] ) && ( $who['result']['structuredContent']['transport'] ?? '' ) === $transport,
        "$transport read tool tron_whoami: user " . ( $who['result']['structuredContent']['user_id'] ?? '?' ) . ', token ' . ( $who['result']['structuredContent']['token_id'] ?? '?' ) );
$status = $call( 'tron_status', new stdClass() );
$layer = (string)( $status['result']['structuredContent']['protocol'] ?? '?' );
echo "     protocol layer: $layer (" . implode( ', ', (array)( $status['result']['structuredContent']['protocol_versions'] ?? array() ) ) . ")\n";

$refused = $call( $deny, array( 'id' => 1, 'decision' => 'protocol proof, refused before it runs' ) );
$check( !empty( $refused['result']['isError'] ) && strpos( $text( $refused ), 'Permission denied' ) !== false, "$transport write $deny refused by policy: " . substr( $text( $refused ), 0, 120 ) );

$draft = $call( 'skill_draft', array( 'manifest' => array( 'name' => 'protocol.proof', 'title' => 'Protocol proof' ) ) );
$check( !empty( $draft['result']['isError'] ) && strpos( $text( $draft ), 'Permission denied' ) === false,
        "$transport write skill_draft allowed by policy, then refused by the skill standard: " . substr( $text( $draft ), 0, 120 ) );

if ( $cmd )
{
    fclose( $pipes[0] );
    stream_get_contents( $pipes[1] );
    fclose( $pipes[1] );
    fclose( $pipes[2] );
    $check( proc_close( $proc ) === 0, "$transport server exit code 0" );
}
echo ( $fails ? 'FAIL' : 'PASS' ) . ": protocol layer $layer over $transport\n";
exit( $fails ? 1 : 0 );
