<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * A three-way merge of lines (diff3): base, left and right are diffed against base; regions both sides kept are
 * stable; between them, a region only one side changed takes that side, a region both changed the same way takes it
 * once, and a region both changed differently is a conflict, written with the usual markers
 * (<<<<<<< left, ||||||| base, =======, >>>>>>> right).
 */
final class tronDiffMerge
{
    /** @return array merged (text), clean (bool), conflicts[{base_line, left, base, right}], chunks[] */
    public static function merge( string $base, string $left, string $right, array $labels = array(), string $algorithm = 'myers' ): array
    {
        $labels += array( 'left' => 'left', 'base' => 'base', 'right' => 'right' );
        $b = tronDiffEngine::tokens( $base, 'line' );
        $l = tronDiffEngine::tokens( $left, 'line' );
        $r = tronDiffEngine::tokens( $right, 'line' );
        $mapL = self::map( tronDiffEngine::diff( $b, $l, $algorithm ) );
        $mapR = self::map( tronDiffEngine::diff( $b, $r, $algorithm ) );
        $nb = count( $b );
        $nl = count( $l );
        $nr = count( $r );
        $i = $li = $ri = 0;
        $out = array();
        $conflicts = array();
        $chunks = array();
        while ( true )
        {
            while ( $i < $nb && ( $mapL[$i] ?? null ) === $li && ( $mapR[$i] ?? null ) === $ri )
            {
                $out[] = $b[$i];
                $i++;
                $li++;
                $ri++;
            }
            if ( $i >= $nb && $li >= $nl && $ri >= $nr )
                break;
            $i2 = $i;
            while ( $i2 < $nb && ( !isset( $mapL[$i2] ) || !isset( $mapR[$i2] ) ) )
                $i2++;
            if ( $i2 < $nb )
            {
                $l2 = $mapL[$i2];
                $r2 = $mapR[$i2];
            }
            else
            {
                $l2 = $nl;
                $r2 = $nr;
            }
            $cb = array_slice( $b, $i, $i2 - $i );
            $cl = array_slice( $l, $li, $l2 - $li );
            $cr = array_slice( $r, $ri, $r2 - $ri );
            if ( $cl === $cb )
            {
                $take = $cr;
                $chunks[] = array( 'kind' => 'right', 'base_line' => $i + 1 );
            }
            else if ( $cr === $cb || $cl === $cr )
            {
                $take = $cl;
                $chunks[] = array( 'kind' => $cl === $cr ? 'both' : 'left', 'base_line' => $i + 1 );
            }
            else
            {
                $nlEnd = function ( array $lines ) {
                    if ( $lines && substr( $lines[count( $lines ) - 1], -1 ) !== "\n" )
                        $lines[count( $lines ) - 1] .= "\n";
                    return $lines;
                };
                $take = array_merge( array( '<<<<<<< ' . $labels['left'] . "\n" ), $nlEnd( $cl ), array( '||||||| ' . $labels['base'] . "\n" ), $nlEnd( $cb ),
                                     array( "=======\n" ), $nlEnd( $cr ), array( '>>>>>>> ' . $labels['right'] . "\n" ) );
                $conflicts[] = array( 'base_line' => $i + 1, 'left' => implode( '', $cl ), 'base' => implode( '', $cb ), 'right' => implode( '', $cr ) );
                $chunks[] = array( 'kind' => 'conflict', 'base_line' => $i + 1 );
            }
            foreach ( $take as $t )
                $out[] = $t;
            $i = $i2;
            $li = $l2;
            $ri = $r2;
        }
        return array( 'merged' => implode( '', $out ), 'clean' => !$conflicts, 'conflicts' => $conflicts, 'chunks' => $chunks );
    }

    /** base index => other index for the lines an edit script keeps */
    private static function map( array $ops ): array
    {
        $m = array();
        foreach ( $ops as $o )
            if ( $o[0] === '=' )
                $m[$o[1]] = $o[2];
        return $m;
    }
}
