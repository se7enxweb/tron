<?php
/**
 * Shared start of the cookbook examples: tron's classes, the test world (SQLite in memory, a fixed clock, users with
 * roles; see tests/lib/fixture.php) and two helpers. Every example runs with plain PHP, no installation needed:
 *
 *   php examples/scripts/01-first-call.php
 *
 * In an installation the same calls go to tronKernel::server() instead of TronFixture::build().
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

error_reporting( E_ALL );
ini_set( 'display_errors', 'stderr' );
require_once dirname( __DIR__ ) . '/autoload.php';
require_once dirname( __DIR__ ) . '/tests/lib/fixture.php';

/** One JSON-RPC request to the server; returns the decoded answer. */
function example_rpc( tronServer $server, tronSession $session, string $method, array $params = array(), $id = 1 ): ?array
{
    $message = array( 'jsonrpc' => '2.0', 'id' => $id, 'method' => $method );
    if ( $params )
        $message['params'] = $params;
    return $server->handle( $message, $session );
}

/** Calls a tool and returns what it answered: the data, or "ERROR: <message>". */
function example_tool( tronServer $server, tronSession $session, string $tool, array $args = array() )
{
    $r = example_rpc( $server, $session, 'tools/call', array( 'name' => $tool, 'arguments' => $args ) );
    if ( isset( $r['error'] ) )
        return 'ERROR ' . $r['error']['code'] . ': ' . $r['error']['message'];
    $data = json_decode( $r['result']['content'][0]['text'], true );
    if ( !empty( $r['result']['isError'] ) )
        return 'ERROR: ' . ( is_array( $data ) && isset( $data['error'] ) ? $data['error'] : $r['result']['content'][0]['text'] );
    return $data;
}
