<?php
/**
 * tron over real HTTP with the test world, for PHP's built-in web server in the conformance test:
 *   TRON_FIXTURE_DB=<sqlite file> php -S 127.0.0.1:<port> tests/fixtures/http_router.php
 * Serves POST /mcp; every other path is 404. Tokens and the audit log live in the SQLite file, so the test can
 * make a token before the request and read the audit after it.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

ini_set( 'display_errors', 'stderr' );
require_once dirname( __DIR__, 2 ) . '/autoload.php';
require_once dirname( __DIR__ ) . '/lib/fixture.php';

if ( parse_url( (string)( $_SERVER['REQUEST_URI'] ?? '/' ), PHP_URL_PATH ) !== '/mcp' )
{
    http_response_code( 404 );
    echo 'not found';
    return true;
}

// the official conformance suite sends no credentials: tests/conformance/official_run.php hands the router a token
if ( getenv( 'TRON_FIXTURE_TOKEN' ) && !isset( $_SERVER['HTTP_AUTHORIZATION'] ) )
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . getenv( 'TRON_FIXTURE_TOKEN' );

$db = new tronDbPdo( new PDO( 'sqlite:' . getenv( 'TRON_FIXTURE_DB' ) ) );
tronSchema::install( $db );
$audit = new tronAuditDb( $db );
$f = TronFixture::build( array( 'db' => $db, 'audit' => $audit ) );
$tokens = $f['tokens'];
$http = new tronHttpTransport( $f['server'], function ( string $t, ?string &$reason = null ) use ( $tokens ) {
    return $tokens->authenticate( $t, $reason );
}, TronFixture::access(), $audit, array( 'allowedHosts' => array( '127.0.0.1' ),
    'allowedOrigins' => array_values( array_filter( explode( ',', (string)getenv( 'TRON_FIXTURE_ORIGINS' ) ), 'strlen' ) ) ) );
$http->handle( tronHttpRequest::fromGlobals( 1048576 ) )->emit();
return true;
