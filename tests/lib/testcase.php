<?php
/**
 * A small test base class, so the suite runs unchanged on every PHP from 7.4 to 8.5 without a PHPUnit that would
 * need a different major version on each.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

class TronAssertionFailed extends Exception
{
}

abstract class TronTestCase
{
    /** @var int */ public $assertions = 0;

    public function setUp(): void
    {
    }

    protected function fail( string $msg ): void
    {
        throw new TronAssertionFailed( $msg );
    }

    protected function assertSame( $expected, $actual, string $msg = '' ): void
    {
        $this->assertions++;
        if ( $expected !== $actual )
            $this->fail( ( $msg !== '' ? $msg . ': ' : '' ) . 'expected ' . self::show( $expected ) . ', got ' . self::show( $actual ) );
    }

    protected function assertTrue( $v, string $msg = '' ): void
    {
        $this->assertSame( true, $v, $msg );
    }

    protected function assertFalse( $v, string $msg = '' ): void
    {
        $this->assertSame( false, $v, $msg );
    }

    protected function assertCount( int $n, $list, string $msg = '' ): void
    {
        $this->assertSame( $n, count( $list ), $msg );
    }

    protected function assertContains( $needle, $haystack, string $msg = '' ): void
    {
        $this->assertions++;
        $ok = is_array( $haystack ) ? in_array( $needle, $haystack, true ) : strpos( (string)$haystack, (string)$needle ) !== false;
        if ( !$ok )
            $this->fail( ( $msg !== '' ? $msg . ': ' : '' ) . self::show( $needle ) . ' not found in ' . self::show( $haystack ) );
    }

    protected function assertNotContains( $needle, $haystack, string $msg = '' ): void
    {
        $this->assertions++;
        $found = is_array( $haystack ) ? in_array( $needle, $haystack, true ) : strpos( (string)$haystack, (string)$needle ) !== false;
        if ( $found )
            $this->fail( ( $msg !== '' ? $msg . ': ' : '' ) . self::show( $needle ) . ' unexpectedly found in ' . self::show( $haystack ) );
    }

    /** Runs $fn and checks it throws $class (and, if given, that the message contains $contains). */
    protected function assertThrows( callable $fn, string $class, string $contains = '' ): void
    {
        $this->assertions++;
        try
        {
            $fn();
        }
        catch ( Throwable $e )
        {
            if ( !$e instanceof $class )
                $this->fail( "expected $class, got " . get_class( $e ) . ': ' . $e->getMessage() );
            if ( $contains !== '' && strpos( $e->getMessage(), $contains ) === false )
                $this->fail( "the message '" . $e->getMessage() . "' does not contain '$contains'" );
            return;
        }
        $this->fail( "expected $class, nothing was thrown" );
    }

    /** The names of tron's own programs (programs/<name>/program.json), in name order: what the loader finds first. */
    protected static function builtinPrograms(): array
    {
        $names = array_map( function ( $p ) { return basename( dirname( $p ) ); }, glob( dirname( __DIR__, 2 ) . '/programs/*/program.json' ) ?: array() );
        sort( $names );
        return $names;
    }

    protected static function show( $v ): string
    {
        $s = json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR );
        return strlen( (string)$s ) > 400 ? substr( (string)$s, 0, 400 ) . '...' : (string)$s;
    }
}
