<?php
/**
 * Runs the official MCP conformance suite (npm package @modelcontextprotocol/conformance) against tron's streamable
 * HTTP endpoint, served by PHP's built-in server with the test world (tests/fixtures/http_router.php):
 *
 *   php tests/conformance/official_run.php --conformance=<dir with node_modules/.bin/conformance> [--scenario=<name>]
 *
 * The suite sends no Authorization header, so the router is given a short-lived editor token through
 * TRON_FIXTURE_TOKEN and puts it on every request that has none, and the server's own origin is allowed
 * (TRON_FIXTURE_ORIGINS); everything else is the real endpoint with its real checks. Prints the
 * suite's output and a line per scenario; exit 0 when the scenarios tron is expected to pass passed.
 *
 * Most server scenarios call tools of the suite's own reference server (test_simple_text, test_image_content, ...);
 * tron has none of them, so those scenarios report "not applicable" here rather than failures. The generic ones
 * (initialize, ping, tools/resources/prompts lists, DNS rebinding protection) must pass.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

require_once dirname( __DIR__, 2 ) . '/autoload.php';
require_once dirname( __DIR__ ) . '/lib/fixture.php';

const TRON_GENERIC_SCENARIOS = array( 'server-initialize', 'ping', 'tools-list', 'resources-list', 'prompts-list', 'dns-rebinding-protection' );

$o = getopt( '', array( 'conformance:', 'scenario:' ) );
$bin = rtrim( (string)( $o['conformance'] ?? '' ), '/' ) . '/node_modules/.bin/conformance';
if ( !is_file( $bin ) )
{
    fwrite( STDERR, "usage: official_run.php --conformance=<dir where npm install @modelcontextprotocol/conformance ran> [--scenario=<name>]\n" );
    exit( 2 );
}

$dir = TronFixture::tmp( 'official' );
foreach ( glob( "$dir/results/*/*" ) ?: array() as $old )
    unlink( $old );
$dbFile = "$dir/tron.sqlite";
file_put_contents( $dbFile, '' );
$db = new tronDbPdo( new PDO( 'sqlite:' . $dbFile ) );
tronSchema::install( $db );
$tokens = new tronTokenService( $db, new tronArrayUserDirectory( TronFixture::users() ), function () { return TronFixture::NOW; } );
$token = $tokens->create( TronFixture::EDITOR, 'official conformance', 1 )['token'];

$probe = stream_socket_server( 'tcp://127.0.0.1:0' );
$name = (string)stream_socket_get_name( $probe, false );
$port = (int)substr( $name, strrpos( $name, ':' ) + 1 );
fclose( $probe );
$env = array_merge( getenv() ?: array(), array( 'TRON_FIXTURE_DB' => $dbFile, 'TRON_FIXTURE_TOKEN' => $token,
                                                       'TRON_FIXTURE_ORIGINS' => "http://127.0.0.1:$port" ) );
$server = proc_open( array( PHP_BINARY, '-S', "127.0.0.1:$port", dirname( __DIR__ ) . '/fixtures/http_router.php' ),
                     array( 0 => array( 'pipe', 'r' ), 1 => array( 'file', "$dir/server.out", 'w' ), 2 => array( 'file', "$dir/server.err", 'w' ) ), $pipes, null, $env );
for ( $i = 0; $i < 100; $i++ )
{
    $s = @stream_socket_client( "tcp://127.0.0.1:$port", $errno, $errstr, 1 );
    if ( $s )
    {
        fclose( $s );
        break;
    }
    usleep( 50000 );
}

$scenarios = isset( $o['scenario'] ) ? array( (string)$o['scenario'] ) : array( null );
$results = array();
foreach ( $scenarios as $scenario )
{
    $cmd = escapeshellarg( $bin ) . ' server --url ' . escapeshellarg( "http://127.0.0.1:$port/mcp" ) . ' -o ' . escapeshellarg( "$dir/results" )
         . ( $scenario ? ' --scenario ' . escapeshellarg( $scenario ) : '' ) . ' 2>&1';
    passthru( $cmd, $code );
}
proc_terminate( $server );
proc_close( $server );

// One line per scenario from the result files: passed, failed, or not applicable (needs the reference server's tools).
$fails = 0;
foreach ( glob( "$dir/results/*/checks.json" ) ?: array() as $f )
{
    $scenario = preg_replace( array( '/-\d{4}-.*$/', '/^server-/' ), '', basename( dirname( $f ) ) );
    $checks = json_decode( (string)file_get_contents( $f ), true ) ?: array();
    $failed = array_filter( $checks, function ( $c ) { return ( $c['status'] ?? '' ) === 'FAILURE'; } );
    $generic = in_array( $scenario, TRON_GENERIC_SCENARIOS, true );
    $word = $failed ? ( $generic ? 'FAIL' : 'N/A ' ) : 'PASS';
    if ( $failed && $generic )
        $fails++;
    echo "$word $scenario" . ( $failed ? ': ' . implode( '; ', array_map( function ( $c ) { return ( $c['name'] ?? '' ) . ' ' . substr( (string)( $c['errorMessage'] ?? '' ), 0, 160 ); }, $failed ) ) : '' ) . "\n";
}
echo ( $fails ? 'FAIL' : 'PASS' ) . ": the generic scenarios, protocol layer " . tronProtocolLayers::resolve( 'auto' ) . "\n";
exit( $fails ? 1 : 0 );
