<?php
/**
 * tron over real stdio with the test world (SQLite in memory, the fixture users and roles), for the conformance
 * test: php tests/fixtures/stdio_server.php, user id from TRON_FIXTURE_USER (default 1, admin).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );
require_once dirname( __DIR__, 2 ) . '/autoload.php';
require_once dirname( __DIR__ ) . '/lib/fixture.php';

$user = (int)( getenv( 'TRON_FIXTURE_USER' ) ?: TronFixture::ADMIN );
$f = TronFixture::build( array( 'logger' => function ( $level, $message ) { fwrite( STDERR, "tron $level: $message\n" ); },
                                'settings' => array( 'ServerSettings' => array( 'PageSize' => (int)( getenv( 'TRON_FIXTURE_PAGE' ) ?: 100 ) ) ) ) );
$session = new tronSession( new tronPrincipal( $user, TronFixture::users()[$user]['login'] ), 'stdio', true, null, 'stdio' );
fwrite( STDERR, "tron fixture serving stdio\n" );
exit( ( new tronStdioTransport( $f['server'], $session, STDIN, STDOUT, 65536 ) )->run() );
