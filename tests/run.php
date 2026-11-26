<?php
/**
 * Runs tron's test suite without an installation: php tests/run.php [filter]
 *
 * Every class in tests/unit and tests/conformance that extends TronTestCase, every public method named test*.
 * Prints one line per test and a summary; exits 0 when all passed, 1 otherwise. A filter runs only the tests whose
 * "Class::method" contains it.
 *
 * Scratch files go to $TRON_TEST_TMP, default <tron>/var/tmp/tron-tests (fixed names, overwritten on every run).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

error_reporting( E_ALL );
ini_set( 'display_errors', 'stderr' );
set_error_handler( function ( $no, $str, $file, $line ) {
    if ( !( error_reporting() & $no ) )
        return false;
    throw new ErrorException( $str, 0, $no, $file, $line );
} );

require_once dirname( __DIR__ ) . '/autoload.php';
require_once __DIR__ . '/lib/testcase.php';
require_once __DIR__ . '/lib/fixture.php';

$filter = $argv[1] ?? '';
$files = array_merge( glob( __DIR__ . '/unit/*Test.php' ) ?: array(), glob( __DIR__ . '/conformance/*Test.php' ) ?: array() );
sort( $files );
$before = get_declared_classes();
foreach ( $files as $f )
    require_once $f;
$classes = array_filter( array_diff( get_declared_classes(), $before ), function ( $c ) {
    return is_subclass_of( $c, 'TronTestCase' ) && !( new ReflectionClass( $c ) )->isAbstract();
} );
sort( $classes );

$pass = 0;
$fail = 0;
$assertions = 0;
$failures = array();
$t0 = microtime( true );
foreach ( $classes as $class )
{
    foreach ( get_class_methods( $class ) as $method )
    {
        if ( strpos( $method, 'test' ) !== 0 )
            continue;
        $name = "$class::$method";
        if ( $filter !== '' && strpos( $name, $filter ) === false )
            continue;
        $t = new $class();
        try
        {
            $t->setUp();
            $t->$method();
            $pass++;
            echo "PASS $name ({$t->assertions})\n";
        }
        catch ( Throwable $e )
        {
            $fail++;
            $where = $e instanceof TronAssertionFailed ? '' : ' [' . get_class( $e ) . ' at ' . basename( $e->getFile() ) . ':' . $e->getLine() . ']';
            $failures[] = "FAIL $name: " . $e->getMessage() . $where;
            echo "FAIL $name: " . $e->getMessage() . $where . "\n";
        }
        $assertions += $t->assertions;
    }
}
$ms = round( ( microtime( true ) - $t0 ) * 1000 );
echo "\n" . ( $fail ? 'FAIL' : 'PASS' ) . ": $pass passed, $fail failed, $assertions assertions, {$ms} ms, PHP " . PHP_VERSION . "\n";
foreach ( $failures as $f )
    echo $f . "\n";
exit( $fail ? 1 : 0 );
