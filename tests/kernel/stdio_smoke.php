<?php
/**
 * Speaks MCP over stdio to a real tron server and checks the answers: the smoke test of an installation.
 *
 *   php <tron>/tests/kernel/stdio_smoke.php [--write] -- php extension/tron/bin/php/tron.php serve --siteaccess=<sa> --user=<login> [--allow-root-user]
 *
 * Read-only by default: initialize, tools/list, tron_whoami, tron_status, a centre list, prompts and resources.
 * With --write it also creates one ticket titled "tron smoke <time>", comments, moves and closes it (on an
 * installation where tron's own centre tables are used), and prints its id. Exit 0 PASS, 1 FAIL.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

$args = array_slice( $argv, 1 );
$write = false;
if ( ( $args[0] ?? '' ) === '--write' )
{
    $write = true;
    array_shift( $args );
}
if ( ( $args[0] ?? '' ) !== '--' || count( $args ) < 2 )
{
    fwrite( STDERR, "usage: stdio_smoke.php [--write] -- <server command ...>\n" );
    exit( 2 );
}
$cmd = array_slice( $args, 1 );
$proc = proc_open( $cmd, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
if ( !is_resource( $proc ) )
{
    fwrite( STDERR, "FAIL: cannot start the server\n" );
    exit( 1 );
}
stream_set_blocking( $pipes[1], false );
stream_set_blocking( $pipes[2], false );

$fails = 0;
$id = 0;
$check = function ( bool $ok, string $what ) use ( &$fails ) {
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $what . "\n";
    if ( !$ok )
        $fails++;
};
$rpc = function ( string $method, ?array $params = null ) use ( $pipes, &$id ) {
    $m = array( 'jsonrpc' => '2.0', 'id' => ++$id, 'method' => $method );
    if ( $params !== null )
        $m['params'] = $params;
    fwrite( $pipes[0], json_encode( $m ) . "\n" );
    fflush( $pipes[0] );
    $buf = '';
    $end = microtime( true ) + 30;
    while ( microtime( true ) < $end )
    {
        $r = array( $pipes[1] );
        $w = $e = null;
        if ( @stream_select( $r, $w, $e, 0, 200000 ) > 0 )
        {
            $chunk = fgets( $pipes[1] );
            if ( $chunk !== false )
            {
                $buf .= $chunk;
                if ( substr( $buf, -1 ) === "\n" )
                    return json_decode( $buf, true ) ?: array( 'unparsable' => $buf );
            }
            else if ( feof( $pipes[1] ) )
                break;
        }
    }
    // The server went away or hung: say why (its stderr) and stop, instead of failing every later check.
    stream_set_blocking( $pipes[2], false );
    $err = trim( (string)stream_get_contents( $pipes[2] ) );
    $err = implode( "\n", array_filter( explode( "\n", $err ), function ( $l ) { return !preg_match( '/MD5|^DETAIL/', $l ); } ) );
    fwrite( STDERR, "FAIL: the server " . ( feof( $pipes[1] ) ? 'exited' : 'did not answer within 30 s' ) . " during $method" . ( $err !== '' ? ":\n$err" : '' ) . "\n" );
    fwrite( STDERR, "(as root, the server command needs --allow-root-user)\n" );
    exit( 1 );
};
$tool = function ( string $name, array $a = array() ) use ( $rpc ) {
    $r = $rpc( 'tools/call', array( 'name' => $name, 'arguments' => (object)$a ) );
    $ok = isset( $r['result'] ) && empty( $r['result']['isError'] );
    return array( $ok, $ok ? ( $r['result']['structuredContent'] ?? json_decode( $r['result']['content'][0]['text'], true ) ) : $r );
};

$init = $rpc( 'initialize', array( 'protocolVersion' => '2025-11-25', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 'tron-smoke', 'version' => '1' ) ) );
$check( ( $init['result']['serverInfo']['name'] ?? '' ) === 'tron', 'initialize: serverInfo.name tron, version ' . ( $init['result']['serverInfo']['version'] ?? '?' ) );
fwrite( $pipes[0], json_encode( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) ) . "\n" );
$list = $rpc( 'tools/list' );
$names = array_column( $list['result']['tools'] ?? array(), 'name' );
$check( in_array( 'tron_status', $names, true ), 'tools/list: ' . count( $names ) . ' tools' );
list( $ok, $who ) = $tool( 'tron_whoami' );
$check( $ok, 'tron_whoami: ' . ( $ok ? $who['login'] . ' over ' . $who['transport'] : json_encode( $who ) ) );
list( $ok, $st ) = $tool( 'tron_status' );
$check( $ok, 'tron_status: ' . ( $ok ? implode( ', ', array_map( function ( $p ) { return $p['name'] . '=' . $p['state']; }, $st['programs'] ) ) : json_encode( $st ) ) );
if ( $ok )
{
    foreach ( $st['programs'] as $p )
        $check( $p['state'] !== 'failed', "program {$p['name']} did not fail" . ( $p['reason'] ? ": {$p['reason']}" : '' ) );
}
$res = $rpc( 'resources/list' );
$check( isset( $res['result']['resources'] ), 'resources/list answers' );
$pr = $rpc( 'prompts/list' );
$check( isset( $pr['result']['prompts'] ), 'prompts/list answers' );

if ( in_array( 'centre_ticket_list', $names, true ) )
{
    list( $ok, $l ) = $tool( 'centre_ticket_list', array( 'limit' => 5 ) );
    $check( $ok, 'centre_ticket_list: ' . ( $ok ? $l['total'] . ' open' : json_encode( $l ) ) );
    if ( $write && in_array( 'centre_ticket_create', $names, true ) )
    {
        list( $ok, $t ) = $tool( 'centre_ticket_create', array( 'title' => 'tron smoke ' . gmdate( 'Y-m-d H:i:s' ), 'body' => 'Created by the tron smoke test; closed right away.', 'labels' => array( 'tron-smoke' ) ) );
        $check( $ok, 'centre_ticket_create: ' . ( $ok ? 'ticket ' . $t['id'] : json_encode( $t ) ) );
        if ( $ok )
        {
            list( $ok2 ) = $tool( 'centre_ticket_comment', array( 'id' => $t['id'], 'body' => 'smoke comment' ) );
            $check( $ok2, 'centre_ticket_comment' );
            list( $ok3, $m ) = $tool( 'centre_ticket_move', array( 'id' => $t['id'], 'lane' => 'doing' ) );
            $check( $ok3 && $m['lane'] === 'doing', 'centre_ticket_move' );
            list( $ok4, $c ) = $tool( 'centre_ticket_close', array( 'id' => $t['id'], 'reason' => 'smoke test' ) );
            $check( $ok4 && $c['status'] === 'closed', 'centre_ticket_close' );
        }
    }
}
$bad = $rpc( 'tools/call', array( 'name' => 'no_such_tool', 'arguments' => new stdClass() ) );
$check( ( $bad['error']['code'] ?? 0 ) === -32602, 'unknown tool: -32602' );

fclose( $pipes[0] );
$end = microtime( true ) + 15;
while ( ( $s = proc_get_status( $proc ) ) && $s['running'] && microtime( true ) < $end )
    usleep( 100000 );
$check( !$s['running'] && (int)$s['exitcode'] === 0, 'server exits 0 at end of input' );
proc_close( $proc );
echo ( $fails ? 'FAIL' : 'PASS' ) . ": stdio smoke, $fails failed\n";
exit( $fails ? 1 : 0 );
