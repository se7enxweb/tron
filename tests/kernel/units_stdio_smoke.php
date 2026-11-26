<?php
/**
 * The units over a real stdio connection: initialize, tools/list (every page), the 20 units and tron_pipe listed,
 * the resource tron://units, and the pipe "node.find class=article limit 3 | node.read | tpl.resolve". Read only.
 *
 *   php <tron>/tests/kernel/units_stdio_smoke.php [--siteaccess=site] -- php extension/tron/bin/php/tron.php serve --token-file=<file> [--allow-root-user]
 *
 * Exit 0 PASS, 1 FAIL.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

$args = array_slice( $argv, 1 );
$site = 'site';
if ( strpos( $args[0] ?? '', '--siteaccess=' ) === 0 )
    $site = substr( array_shift( $args ), 13 );
if ( ( $args[0] ?? '' ) !== '--' || count( $args ) < 2 )
{
    fwrite( STDERR, "usage: units_stdio_smoke.php [--siteaccess=site] -- <server command ...>\n" );
    exit( 2 );
}
$proc = proc_open( array_slice( $args, 1 ), array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
if ( !is_resource( $proc ) )
{
    echo "FAIL: the server did not start\n";
    exit( 1 );
}
$id = 0;
$rpc = function ( string $method, array $params = array() ) use ( $pipes, &$id ) {
    $id++;
    fwrite( $pipes[0], json_encode( array( 'jsonrpc' => '2.0', 'id' => $id, 'method' => $method, 'params' => (object)$params ) ) . "\n" );
    fflush( $pipes[0] );
    while ( ( $line = fgets( $pipes[1] ) ) !== false )
    {
        $m = json_decode( $line, true );
        if ( is_array( $m ) && ( $m['id'] ?? null ) === $id )
            return $m;
    }
    return array( 'error' => array( 'message' => 'the server closed the connection' ) );
};
$fails = 0;
$check = function ( bool $ok, string $what ) use ( &$fails ) {
    echo ( $ok ? 'PASS ' : 'FAIL ' ) . $what . "\n";
    if ( !$ok )
        $fails++;
};

$t0 = microtime( true );
$init = $rpc( 'initialize', array( 'protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 'units-smoke', 'version' => '1' ) ) );
$check( isset( $init['result']['serverInfo'] ), 'initialize: ' . ( $init['result']['serverInfo']['name'] ?? '?' ) . ' ' . ( $init['result']['serverInfo']['version'] ?? '?' ) );
fwrite( $pipes[0], json_encode( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) ) . "\n" );

$tools = array();
$cursor = null;
do
{
    $r = $rpc( 'tools/list', $cursor ? array( 'cursor' => $cursor ) : array() );
    foreach ( $r['result']['tools'] ?? array() as $t )
        $tools[$t['name']] = $t;
    $cursor = $r['result']['nextCursor'] ?? null;
}
while ( $cursor );
$units = array( 'ini_resolve', 'ini_diff', 'ini_list', 'tpl_resolve', 'tpl_lint', 'tpl_source', 'node_read', 'node_tree', 'node_find',
                'node_versions', 'class_describe', 'class_list', 'class_diff', 'layout_list', 'layout_read', 'layout_rule_list',
                'layout_rule_resolve', 'layout_block_definitions', 'role_describe', 'role_user_find', 'tron_pipe' );
$missing = array_diff( $units, array_keys( $tools ) );
$check( !$missing, 'tools/list: ' . count( $tools ) . ' tools, the 20 units and tron_pipe among them' . ( $missing ? ' (missing ' . implode( ', ', $missing ) . ')' : '' ) );
$withSchema = array_filter( $units, function ( $n ) use ( $tools ) { return isset( $tools[$n]['outputSchema'] ); } );
echo '     ' . count( $withSchema ) . " of 21 list an outputSchema\n";
foreach ( array_chunk( array_intersect( $units, array_keys( $tools ) ), 7 ) as $row )
    echo '     ' . implode( ' ', $row ) . "\n";

$r = $rpc( 'resources/read', array( 'uri' => 'tron://units' ) );
$d = json_decode( $r['result']['contents'][0]['text'] ?? 'null', true );
$check( count( $d['units'] ?? array() ) === 20, 'tron://units: ' . count( $d['units'] ?? array() ) . ' units, ' . count( $d['types'] ?? array() ) . ' types' );

$r = $rpc( 'tools/call', array( 'name' => 'tron_pipe', 'arguments' => array( 'pipe' => 'node.find class=article limit 3 | node.read | tpl.resolve siteaccess=' . $site ) ) );
$p = json_decode( $r['result']['content'][0]['text'] ?? 'null', true );
$check( empty( $r['result']['isError'] ) && ( $p['count'] ?? 0 ) === 3, 'tron_pipe "' . ( $p['pipe'] ?? '' ) . '": ' . ( $p['count'] ?? 0 ) . ' ' . ( $p['out'] ?? '' ) . ', ' . ( $p['calls'] ?? 0 ) . ' calls, ' . ( $p['ms'] ?? '?' ) . ' ms' );
foreach ( $p['steps'] ?? array() as $s )
    echo "     step {$s['step']} {$s['unit']}: {$s['in']} -> {$s['out']}, {$s['calls']} calls, {$s['ms']} ms\n";
foreach ( $p['results'] ?? array() as $c )
    echo "     node {$c['node_id']}: {$c['winner']['path']} ({$c['winner']['by']}: {$c['winner']['reason']})\n";

fclose( $pipes[0] );
proc_close( $proc );
printf( "\n%s: units over stdio, %.1f s\n", $fails ? 'FAIL' : 'PASS', microtime( true ) - $t0 );
exit( $fails ? 1 : 0 );
