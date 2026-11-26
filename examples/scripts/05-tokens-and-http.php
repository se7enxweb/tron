<?php
/**
 * Example 5: tokens and the HTTP transport. Make a scoped token, call over HTTP (in process, the same code the
 * endpoint /tron/mcp runs), see the refusals, revoke. The token itself is never printed.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

require __DIR__ . '/../bootstrap.php';

$w = TronFixture::build();
$made = $w['tokens']->create( TronFixture::ADMIN, 'claude@cluster', 30, array( 'centre' ) );
echo "token for admin, scopes " . implode( ',', $made['scopes'] ) . ', expires ' . gmdate( 'Y-m-d', $made['expires'] ) . "\n";

$tokens = $w['tokens'];
$http = new tronHttpTransport( $w['server'], function ( string $t, ?string &$reason = null ) use ( $tokens ) { return $tokens->authenticate( $t, $reason ); },
                               TronFixture::access(), $w['audit'], array( 'allowedHosts' => array( 'centre.example.org' ) ) );
$post = function ( array $body, array $headers ) use ( $http ) {
    return $http->handle( new tronHttpRequest( 'POST', $headers + array( 'Host' => 'centre.example.org', 'Content-Type' => 'application/json' ), json_encode( $body ), '203.0.113.5' ) );
};
$auth = array( 'Authorization' => 'Bearer ' . $made['token'], 'MCP-Protocol-Version' => '2025-06-18' );

$r = $post( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list' ), $auth );
$names = array_column( json_decode( $r->body, true )['result']['tools'], 'name' );
echo "HTTP {$r->status}: " . count( $names ) . ' tools; katalyst tools listed: ' . ( preg_grep( '/^katalyst_/', $names ) ? 'yes' : 'no (out of the token\'s scope)' ) . "\n";

echo 'no token:      HTTP ' . $post( array( 'jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping' ), array() )->status . "\n";
echo 'other origin:  HTTP ' . $post( array( 'jsonrpc' => '2.0', 'id' => 3, 'method' => 'ping' ), $auth + array( 'Origin' => 'https://evil.example' ) )->status . "\n";
echo 'notification:  HTTP ' . $post( array( 'jsonrpc' => '2.0', 'method' => 'notifications/initialized' ), $auth )->status . "\n";

$w['tokens']->revoke( $made['prefix'] );
echo 'after revoke:  HTTP ' . $post( array( 'jsonrpc' => '2.0', 'id' => 4, 'method' => 'ping' ), $auth )->status . "\n";
