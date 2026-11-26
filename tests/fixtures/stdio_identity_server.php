<?php
/**
 * tron serve's identity over real stdio, with the test world: php tests/fixtures/stdio_identity_server.php [--user=<login>]
 *
 * The identity is chosen exactly as tron.php serve chooses it (tronStdioIdentity::resolve): TRON_TOKEN, else
 * --user=<login>. The database is the SQLite file TRON_FIXTURE_DB (tron's tables, the token the test made);
 * TRON_FIXTURE_LOGIN_OPTION, when set, is [StdioSettings] AllowLoginOption (unset: the setting is absent).
 * Refused: the line on stderr, nothing on stdout, exit 1 (2 for usage), as tron.php serve.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

ini_set( 'display_errors', 'stderr' );
error_reporting( E_ALL );
require_once dirname( __DIR__, 2 ) . '/autoload.php';
require_once dirname( __DIR__ ) . '/lib/fixture.php';

$login = '';
foreach ( array_slice( $argv, 1 ) as $a )
{
    if ( strpos( $a, '--user=' ) === 0 )
        $login = substr( $a, 7 );
}
$settings = array();
if ( getenv( 'TRON_FIXTURE_LOGIN_OPTION' ) !== false )
    $settings['StdioSettings'] = array( 'AllowLoginOption' => (string)getenv( 'TRON_FIXTURE_LOGIN_OPTION' ) );

$f = TronFixture::build( array( 'db' => new tronDbPdo( new PDO( 'sqlite:' . getenv( 'TRON_FIXTURE_DB' ) ) ), 'settings' => $settings ) );
list( $principal, $code, $line ) = tronStdioIdentity::resolve( $settings, (string)( getenv( 'TRON_TOKEN' ) ?: '' ), $login, $f['tokens'], $f['users'] );
if ( !$principal )
{
    fwrite( STDERR, $line . "\n" );
    exit( $code );
}
$session = new tronSession( $principal, 'stdio', true, null, 'stdio' );
fwrite( STDERR, "tron fixture serving stdio as {$principal->login}\n" );
exit( ( new tronStdioTransport( $f['server'], $session, STDIN, STDOUT, 65536 ) )->run() );
