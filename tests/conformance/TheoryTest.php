<?php
/**
 * doc/theory.md ties every claim to a test. This test fails when a test it names does not exist, so a renamed or
 * removed test cannot leave a claim without its proof.
 *
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

final class TronTheoryTest extends TronTestCase
{
    public function testEveryProofNamedHereExists(): void
    {
        $doc = (string)file_get_contents( dirname( __DIR__, 2 ) . '/doc/theory.md' );
        preg_match_all( '/`(Tron[A-Za-z]+Test)::(test[A-Za-z]+)`/', $doc, $m, PREG_SET_ORDER );
        $this->assertTrue( count( $m ) >= 30, 'theory.md names its proofs (' . count( $m ) . ')' );
        foreach ( $m as $ref )
        {
            $this->assertTrue( class_exists( $ref[1], false ), "theory.md names the test class {$ref[1]}" );
            $this->assertTrue( method_exists( $ref[1], $ref[2] ), "theory.md names {$ref[1]}::{$ref[2]}, which exists" );
        }
        preg_match_all( '/\*\*C(\d+\.\d+)/', $doc, $claims );
        $this->assertSame( count( $claims[1] ), count( array_unique( $claims[1] ) ), 'claim numbers are unique' );
    }
}
