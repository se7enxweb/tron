<?php
/**
 * Example 8: a client over real stdio, as an MCP client sees tron. It starts a server process and speaks
 * newline-delimited JSON-RPC to it. Against an installation, start instead:
 *   TRON_TOKEN=<token> php extension/tron/bin/php/tron.php serve --siteaccess=<sa> [--allow-root-user]
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

$server = array( PHP_BINARY, dirname( __DIR__, 2 ) . '/tests/fixtures/stdio_server.php' );
$proc = proc_open( $server, array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );

$send = function ( array $message ) use ( $pipes ) {
    fwrite( $pipes[0], json_encode( $message ) . "\n" );
    fflush( $pipes[0] );
};
$receive = function () use ( $pipes ) {
    return json_decode( (string)fgets( $pipes[1] ), true );     // one answer per line
};

$send( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array(
    'protocolVersion' => '2025-06-18', 'capabilities' => new stdClass(), 'clientInfo' => array( 'name' => 'cookbook', 'version' => '1' ) ) ) );
$init = $receive();
echo "initialize -> {$init['result']['serverInfo']['name']}, protocol {$init['result']['protocolVersion']}\n";
$send( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ) );           // no answer comes back

$send( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call', 'params' => array(
    'name' => 'centre_ticket_create', 'arguments' => array( 'title' => 'Made over stdio' ) ) ) );
$r = $receive();
echo "tools/call -> ticket #{$r['result']['structuredContent']['id']} \"{$r['result']['structuredContent']['title']}\"\n";

$send( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => array( 'name' => 'nope_nothing', 'arguments' => new stdClass() ) ) );
$e = $receive();
echo "unknown tool -> {$e['error']['code']} {$e['error']['message']}\n";

fclose( $pipes[0] );                                                                    // end of input: the server ends
fclose( $pipes[1] );
fclose( $pipes[2] );
echo 'server exit code: ' . proc_close( $proc ) . "\n";
