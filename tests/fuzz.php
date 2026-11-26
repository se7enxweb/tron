<?php
/**
 * The contract fuzzer, standalone (doc/contract-testing.md):
 *
 *   php tests/fuzz.php [--per-tool=2000] [--seed=1] [--world=tables|bridge|all] [--dsn=pgsql:dbname=postgres [--peer-user=postgres]]
 *                      [--programs=tron,centre,...] [--quiet] [--show=<tool>|*]
 *
 * Runs every tool of every world with --per-tool inputs (valid, boundary, malformed, injection, unicode, huge,
 * canary), each on two identical worlds, and checks every answer: no internal error or PHP message, the output schema
 * holds, no canary secret and no other user's data, the same answer from both worlds. Prints one line per tool and a
 * summary; exits 0 only when nothing was found.
 *
 * --dsn runs the worlds on that database instead of SQLite in memory: each world in a schema of its own (PostgreSQL)
 * inside a transaction that is rolled back at the end, so the database is left as it was.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

error_reporting( E_ALL );
ini_set( 'display_errors', 'stderr' );
ini_set( 'memory_limit', '2G' );
set_error_handler( function ( $no, $str, $file, $line ) {
    if ( !( error_reporting() & $no ) )
        return false;
    throw new ErrorException( $str, 0, $no, $file, $line );
} );

require_once dirname( __DIR__ ) . '/autoload.php';
require_once __DIR__ . '/lib/testcase.php';
require_once __DIR__ . '/lib/fixture.php';
require_once __DIR__ . '/lib/fuzz.php';
require_once __DIR__ . '/lib/fuzzworld.php';
require_once __DIR__ . '/lib/fuzzrun.php';

$opt = array( 'per-tool' => 2000, 'seed' => 1, 'world' => 'all', 'dsn' => '', 'peer-user' => '', 'quiet' => false, 'show' => '', 'programs' => '' );
foreach ( array_slice( $argv, 1 ) as $a )
{
    if ( preg_match( '/^--([a-z-]+)(?:=(.*))?$/', $a, $m ) && array_key_exists( $m[1], $opt ) )
        $opt[$m[1]] = isset( $m[2] ) ? $m[2] : true;
    else
    {
        fwrite( STDERR, "unknown argument $a\n" );
        exit( 2 );
    }
}
$worlds = $opt['world'] === 'all' ? array( 'tables', 'bridge' ) : array( (string)$opt['world'] );
$engine = 'sqlite';
$connections = array();
$schemaN = 0;
$make = function ( string $kind ) use ( $opt, &$engine, &$connections, &$schemaN ) {
    if ( $opt['dsn'] === '' )
        return TronFuzzWorld::build( $kind );
    // --peer-user: connect as that system user (local PostgreSQL with peer authentication), then carry on as before
    $uid = null;
    if ( $opt['peer-user'] !== '' )
    {
        $pw = function_exists( 'posix_getpwnam' ) ? posix_getpwnam( (string)$opt['peer-user'] ) : false;
        if ( !$pw || !posix_seteuid( (int)$pw['uid'] ) )
            throw new RuntimeException( "cannot act as the user {$opt['peer-user']} (needs root and the posix extension)" );
        $uid = 0;
    }
    try
    {
        $pdo = new PDO( (string)$opt['dsn'] );
    }
    finally
    {
        if ( $uid !== null )
            posix_seteuid( $uid );
    }
    $pdo->setAttribute( PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION );
    $engine = (string)$pdo->getAttribute( PDO::ATTR_DRIVER_NAME );
    if ( $engine !== 'pgsql' )
        throw new RuntimeException( "--dsn supports PostgreSQL (its DDL is transactional); got $engine" );
    $pdo->beginTransaction();
    $schema = 'tron_fuzz_' . getmypid() . '_' . ( ++$schemaN );
    $pdo->exec( 'CREATE SCHEMA ' . $schema );
    $pdo->exec( 'SET LOCAL search_path TO ' . $schema );
    $connections[] = $pdo;
    return TronFuzzWorld::build( $kind, $pdo );
};

$total = 0;
$found = array();
$t0 = microtime( true );
foreach ( $worlds as $kind )
{
    $r = TronFuzzRun::run( function () use ( $make, $kind ) { return $make( $kind ); }, (int)$opt['per-tool'], (int)$opt['seed'],
        $opt['quiet'] ? null : function ( $name, $s ) use ( $kind ) {
            printf( "%s %-8s %-28s %6d inputs  ok %5d  tool error %5d  rpc error %5d  problems %d\n", $s['problems'] ? 'FAIL' : 'PASS', $kind, $name,
                    $s['inputs'], $s['ok'], $s['tool_error'], $s['rpc_error'], $s['problems'] );
        }, $opt['show'] === '' ? null : (string)$opt['show'], array_values( array_filter( explode( ',', (string)$opt['programs'] ) ) ) );
    $total += $r['inputs'];
    printf( "%s world %s: %d tools, %d inputs (+ protocol), %d audit rows, %d ms\n", $r['problems'] ? 'FAIL' : 'PASS', $kind, count( $r['tools'] ), $r['inputs'], $r['audit_rows'], $r['ms'] );
    foreach ( $r['problems'] as $p )
        $found[] = "$kind: $p";
}
foreach ( $connections as $pdo )
    $pdo->rollBack();
printf( "\n%s: %d inputs on %s, PHP %s, %d problems, %d ms\n", $found ? 'FAIL' : 'PASS', $total, $engine, PHP_VERSION, count( $found ), (int)round( ( microtime( true ) - $t0 ) * 1000 ) );
foreach ( array_slice( $found, 0, 60 ) as $p )
    echo "  $p\n";
exit( $found ? 1 : 0 );
