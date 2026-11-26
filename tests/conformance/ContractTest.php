<?php
/**
 * The tool contract (doc/contract-testing.md): every tool declares an input and an output schema, every write tool a
 * dry run, and a short fuzz run of every tool in both worlds passes every check. The long run is tests/fuzz.php
 * (thousands of inputs per tool); TRON_FUZZ_PER_TOOL sets this one's size (default 40).
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

require_once dirname( __DIR__ ) . '/lib/fuzz.php';
require_once dirname( __DIR__ ) . '/lib/fuzzworld.php';
require_once dirname( __DIR__ ) . '/lib/fuzzrun.php';

final class TronContractTest extends TronTestCase
{
    public function testEveryToolDeclaresItsContract(): void
    {
        foreach ( array( 'tables', 'bridge' ) as $kind )
        {
            $w = TronFuzzWorld::build( $kind );
            $n = 0;
            foreach ( $w->server->registry()->tools() as $t )
            {
                $this->assertSame( 'object', $t->inputSchema['type'] ?? null, "{$t->name} input schema" );
                $this->assertTrue( is_array( $t->outputSchema ) && ( $t->outputSchema['type'] ?? null ) === 'object', "{$t->name} declares an output schema" );
                if ( $t->write && $t->name !== 'tron_apply' )
                    $this->assertTrue( $t->hasDryRun(), "the write tool {$t->name} has a dry run" );
                $def = $t->definition( true );
                $this->assertSame( $t->outputSchema, $def['outputSchema'], "{$t->name} lists its output schema from 2025-06-18 on" );
                $this->assertFalse( isset( $t->definition( false )['outputSchema'] ), "{$t->name} leaves it out for older clients" );
                $n++;
            }
            $this->assertTrue( $n >= 20, "$kind: $n tools" );
        }
    }

    public function testFuzzEveryTool(): void
    {
        $per = (int)( getenv( 'TRON_FUZZ_PER_TOOL' ) ?: 40 );
        foreach ( array( 'tables', 'bridge' ) as $kind )
        {
            $r = TronFuzzRun::run( function () use ( $kind ) { return TronFuzzWorld::build( $kind ); }, $per, 20261124 );
            $this->assertSame( array(), $r['problems'], "$kind world" );
            foreach ( $r['tools'] as $name => $s )
            {
                $this->assertSame( $per, $s['inputs'], "$name inputs" );
                $this->assertSame( count( TronFuzz::CATEGORIES ), count( $s['categories'] ), "$name gets every category" );
            }
        }
    }
}
