<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/** The release number of tron (the same number as VERSION, ezinfo.php and extension.xml) and a four-part compare. */
final class tronVersion
{
    const VERSION = '0.0.0.12';

    /** The copyright notice every file of tron carries (tests/unit/CopyrightNoticeTest.php), and its license sentence. */
    const NOTICE = 'Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.';
    const LICENSE = 'Licensed under the GNU General Public License v2.0 (or any later version).';

    /**
     * Compares two versions position by position as integers (X.Y.Z.N; a three-part version reads as X.Y.Z.0).
     * Returns -1, 0 or 1. Text order would put 0.0.0.10 before 0.0.0.9; this does not.
     */
    public static function compare( string $a, string $b ): int
    {
        $pa = self::parts( $a );
        $pb = self::parts( $b );
        for ( $i = 0; $i < 4; $i++ )
        {
            if ( $pa[$i] !== $pb[$i] )
                return $pa[$i] < $pb[$i] ? -1 : 1;
        }
        return 0;
    }

    public static function isValid( string $v ): bool
    {
        return (bool)preg_match( '/^\d+\.\d+\.\d+(\.\d+)?$/', $v );
    }

    /** @return int[] */
    private static function parts( string $v ): array
    {
        $p = array_map( 'intval', explode( '.', $v ) );
        while ( count( $p ) < 4 )
            $p[] = 0;
        return array_slice( $p, 0, 4 );
    }
}
