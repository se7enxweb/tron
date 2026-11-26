<?php
/**
 * Every example of doc/cookbook.md runs, as a separate PHP process, and prints exactly its .expected output. A change
 * that alters what an example shows fails here first, so the cookbook never drifts from the code.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronCookbookTest extends TronTestCase
{
    public function testEveryExampleRunsAndPrintsItsExpectedOutput(): void
    {
        $dir = dirname( __DIR__, 2 ) . '/examples/scripts';
        $scripts = glob( "$dir/*.php" ) ?: array();
        sort( $scripts );
        $this->assertTrue( count( $scripts ) >= 8, 'the cookbook has its examples' );
        foreach ( $scripts as $script )
        {
            $expected = substr( $script, 0, -4 ) . '.expected';
            $this->assertTrue( is_file( $expected ), basename( $script ) . ' has an .expected file' );
            $proc = proc_open( array( PHP_BINARY, '-d', 'display_errors=stderr', $script ),
                               array( 0 => array( 'pipe', 'r' ), 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
            fclose( $pipes[0] );
            $out = stream_get_contents( $pipes[1] );
            $err = stream_get_contents( $pipes[2] );
            fclose( $pipes[1] );
            fclose( $pipes[2] );
            $code = proc_close( $proc );
            $this->assertSame( 0, $code, basename( $script ) . ' exits 0' . ( $err !== '' ? ": $err" : '' ) );
            $this->assertSame( (string)file_get_contents( $expected ), $out, basename( $script ) . ' prints its expected output' );
        }
    }

    /** Every example the cookbook shows exists, and every example is shown in the cookbook. */
    public function testCookbookAndExamplesMatch(): void
    {
        $root = dirname( __DIR__, 2 );
        $doc = (string)file_get_contents( "$root/doc/cookbook.md" );
        foreach ( glob( "$root/examples/scripts/*.php" ) ?: array() as $script )
            $this->assertContains( 'examples/scripts/' . basename( $script ), $doc, 'doc/cookbook.md shows ' . basename( $script ) );
        preg_match_all( '#examples/scripts/([0-9a-z-]+\.php)#', $doc, $m );
        foreach ( array_unique( $m[1] ) as $named )
            $this->assertTrue( is_file( "$root/examples/scripts/$named" ), "doc/cookbook.md names $named, which exists" );
    }
}
