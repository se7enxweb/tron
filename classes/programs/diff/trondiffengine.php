<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The diff engines: Myers' O(ND) shortest edit script and patience diff (unique lines as anchors, Myers between
 * them), on any list of tokens (lines, words, characters).
 *
 * An edit script is a list of ops: array( '=', i, j ), array( '-', i, null ), array( '+', null, j ), where i indexes
 * the left list and j the right. Deletions come before insertions inside a changed run, so renderers can pair them.
 */
final class tronDiffEngine
{
    /** Tokens above which a diff is refused (each side). */
    const MAX_TOKENS = 50000;
    /** Edit distance above which Myers gives up and reports the whole range as replaced (keeps time bounded). */
    const MAX_D = 6000;

    /** @return string[] */
    public static function tokens( string $text, string $granularity ): array
    {
        if ( $text === '' )
            return array();
        switch ( $granularity )
        {
            case 'char':
                return preg_split( '//u', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: str_split( $text );
            case 'word':
                return preg_split( '/(\s+|[^\p{L}\p{N}_\s])/u', $text, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY ) ?: array( $text );
            default:
                // lines keep their end, so "a" and "a\n" differ only where the file end differs
                return preg_split( '/(?<=\n)/', $text, -1, PREG_SPLIT_NO_EMPTY ) ?: array( $text );
        }
    }

    /**
     * @param string[] $a
     * @param string[] $b
     * @param callable|null $key how tokens compare (e.g. ignoring whitespace); null: as they are
     * @return array[] ops
     */
    public static function diff( array $a, array $b, string $algorithm = 'myers', ?callable $key = null ): array
    {
        if ( count( $a ) > self::MAX_TOKENS || count( $b ) > self::MAX_TOKENS )
            throw new tronToolError( 'Too much to compare: at most ' . self::MAX_TOKENS . ' tokens a side (use a coarser granularity)' );
        $ka = $key ? array_map( $key, $a ) : array_values( $a );
        $kb = $key ? array_map( $key, $b ) : array_values( $b );
        $ops = array();
        if ( $algorithm === 'patience' )
            self::patience( $ka, $kb, 0, count( $ka ), 0, count( $kb ), $ops );
        else
            self::myersRange( $ka, $kb, 0, count( $ka ), 0, count( $kb ), $ops );
        return self::order( $ops );
    }

    /** Diffs the range a[a0..a1) against b[b0..b1), appending ops; common prefix and suffix first. */
    private static function myersRange( array $a, array $b, int $a0, int $a1, int $b0, int $b1, array &$ops ): void
    {
        $pre = array();
        while ( $a0 < $a1 && $b0 < $b1 && $a[$a0] === $b[$b0] )
            $pre[] = array( '=', $a0++, $b0++ );
        $post = array();
        while ( $a1 > $a0 && $b1 > $b0 && $a[$a1 - 1] === $b[$b1 - 1] )
            array_unshift( $post, array( '=', --$a1, --$b1 ) );
        foreach ( $pre as $o )
            $ops[] = $o;
        foreach ( self::myers( $a, $b, $a0, $a1, $b0, $b1 ) as $o )
            $ops[] = $o;
        foreach ( $post as $o )
            $ops[] = $o;
    }

    /** Myers' greedy algorithm with the V arrays kept per step for the backtrack. */
    private static function myers( array $a, array $b, int $a0, int $a1, int $b0, int $b1 ): array
    {
        $n = $a1 - $a0;
        $m = $b1 - $b0;
        if ( $n === 0 && $m === 0 )
            return array();
        if ( $n === 0 || $m === 0 )
            return self::replaced( $a0, $a1, $b0, $b1 );
        $max = $n + $m;
        $v = array( 1 => 0 );
        $trace = array();
        $found = false;
        for ( $d = 0; $d <= $max; $d++ )
        {
            if ( $d > self::MAX_D )
                return self::replaced( $a0, $a1, $b0, $b1 );
            $trace[] = $v;
            for ( $k = -$d; $k <= $d; $k += 2 )
            {
                if ( $k === -$d || $k !== $d && ( $v[$k - 1] ?? -1 ) < ( $v[$k + 1] ?? -1 ) )
                    $x = $v[$k + 1] ?? 0;
                else
                    $x = ( $v[$k - 1] ?? 0 ) + 1;
                $y = $x - $k;
                while ( $x < $n && $y < $m && $a[$a0 + $x] === $b[$b0 + $y] )
                {
                    $x++;
                    $y++;
                }
                $v[$k] = $x;
                if ( $x >= $n && $y >= $m )
                {
                    $found = true;
                    break 2;
                }
            }
        }
        // backtrack
        $ops = array();
        $x = $n;
        $y = $m;
        for ( $d = count( $trace ) - 1; $d >= 0; $d-- )
        {
            $v = $trace[$d];
            $k = $x - $y;
            if ( $k === -$d || $k !== $d && ( $v[$k - 1] ?? -1 ) < ( $v[$k + 1] ?? -1 ) )
                $pk = $k + 1;
            else
                $pk = $k - 1;
            $px = $v[$pk] ?? 0;
            $py = $px - $pk;
            while ( $x > $px && $y > $py )
            {
                $ops[] = array( '=', $a0 + --$x, $b0 + --$y );
            }
            if ( $d > 0 )
            {
                if ( $x === $px )
                    $ops[] = array( '+', null, $b0 + --$y );
                else
                    $ops[] = array( '-', $a0 + --$x, null );
            }
        }
        return array_reverse( $ops );
    }

    private static function replaced( int $a0, int $a1, int $b0, int $b1 ): array
    {
        $ops = array();
        for ( $i = $a0; $i < $a1; $i++ )
            $ops[] = array( '-', $i, null );
        for ( $j = $b0; $j < $b1; $j++ )
            $ops[] = array( '+', null, $j );
        return $ops;
    }

    /** Patience diff: tokens unique on both sides, their longest increasing run as anchors, recursion between. */
    private static function patience( array $a, array $b, int $a0, int $a1, int $b0, int $b1, array &$ops, int $depth = 0 ): void
    {
        while ( $a0 < $a1 && $b0 < $b1 && $a[$a0] === $b[$b0] )
            $ops[] = array( '=', $a0++, $b0++ );
        $tail = array();
        while ( $a1 > $a0 && $b1 > $b0 && $a[$a1 - 1] === $b[$b1 - 1] )
            array_unshift( $tail, array( '=', --$a1, --$b1 ) );
        if ( $a0 === $a1 || $b0 === $b1 || $depth > 200 )
        {
            self::myersRange( $a, $b, $a0, $a1, $b0, $b1, $ops );
            foreach ( $tail as $o )
                $ops[] = $o;
            return;
        }
        $ca = array();
        for ( $i = $a0; $i < $a1; $i++ )
            $ca[$a[$i]] = isset( $ca[$a[$i]] ) ? -1 : $i;
        $cb = array();
        for ( $j = $b0; $j < $b1; $j++ )
            $cb[$b[$j]] = isset( $cb[$b[$j]] ) ? -1 : $j;
        $pairs = array();
        for ( $i = $a0; $i < $a1; $i++ )
        {
            $t = $a[$i];
            if ( $ca[$t] === $i && isset( $cb[$t] ) && $cb[$t] >= 0 )
                $pairs[] = array( $i, $cb[$t] );
        }
        $anchors = self::lis( $pairs );
        if ( !$anchors )
        {
            self::myersRange( $a, $b, $a0, $a1, $b0, $b1, $ops );
        }
        else
        {
            $pa = $a0;
            $pb = $b0;
            foreach ( $anchors as $p )
            {
                self::patience( $a, $b, $pa, $p[0], $pb, $p[1], $ops, $depth + 1 );
                $ops[] = array( '=', $p[0], $p[1] );
                $pa = $p[0] + 1;
                $pb = $p[1] + 1;
            }
            self::patience( $a, $b, $pa, $a1, $pb, $b1, $ops, $depth + 1 );
        }
        foreach ( $tail as $o )
            $ops[] = $o;
    }

    /** The longest run of pairs increasing in their second element (patience sorting). */
    private static function lis( array $pairs ): array
    {
        $tops = array();
        $back = array();
        foreach ( $pairs as $idx => $p )
        {
            $lo = 0;
            $hi = count( $tops );
            while ( $lo < $hi )
            {
                $mid = ( $lo + $hi ) >> 1;
                if ( $pairs[$tops[$mid]][1] < $p[1] )
                    $lo = $mid + 1;
                else
                    $hi = $mid;
            }
            $back[$idx] = $lo > 0 ? $tops[$lo - 1] : null;
            $tops[$lo] = $idx;
        }
        if ( !$tops )
            return array();
        $out = array();
        for ( $i = $tops[count( $tops ) - 1]; $i !== null; $i = $back[$i] )
            $out[] = $pairs[$i];
        return array_reverse( $out );
    }

    /** Inside every changed run, deletions first, then insertions. */
    private static function order( array $ops ): array
    {
        $out = array();
        $del = array();
        $ins = array();
        foreach ( $ops as $o )
        {
            if ( $o[0] === '=' )
            {
                array_push( $out, ...$del, ...$ins );
                $del = $ins = array();
                $out[] = $o;
            }
            else if ( $o[0] === '-' )
                $del[] = $o;
            else
                $ins[] = $o;
        }
        array_push( $out, ...$del, ...$ins );
        return $out;
    }

    /** added, removed, unchanged token counts of an edit script */
    public static function summary( array $ops ): array
    {
        $s = array( 'added' => 0, 'removed' => 0, 'unchanged' => 0 );
        foreach ( $ops as $o )
            $s[$o[0] === '=' ? 'unchanged' : ( $o[0] === '+' ? 'added' : 'removed' )]++;
        return $s;
    }

    /**
     * Hunks of a line edit script with $context lines around each change: array( a_start, a_len, b_start, b_len,
     * ops ) with 1-based starts as unified diff writes them.
     */
    public static function hunks( array $ops, int $context = 3 ): array
    {
        $n = count( $ops );
        $changes = array();
        foreach ( $ops as $i => $o )
            if ( $o[0] !== '=' )
                $changes[] = $i;
        if ( !$changes )
            return array();
        $ranges = array();
        foreach ( $changes as $i )
        {
            $from = max( 0, $i - $context );
            $to = min( $n - 1, $i + $context );
            if ( $ranges && $from <= $ranges[count( $ranges ) - 1][1] + 1 )
                $ranges[count( $ranges ) - 1][1] = max( $ranges[count( $ranges ) - 1][1], $to );
            else
                $ranges[] = array( $from, $to );
        }
        // the position in each side before op k
        $posA = array();
        $posB = array();
        $pa = 0;
        $pb = 0;
        foreach ( $ops as $k => $o )
        {
            $posA[$k] = $pa;
            $posB[$k] = $pb;
            if ( $o[0] !== '+' )
                $pa++;
            if ( $o[0] !== '-' )
                $pb++;
        }
        $hunks = array();
        foreach ( $ranges as $r )
        {
            $slice = array_slice( $ops, $r[0], $r[1] - $r[0] + 1 );
            $la = 0;
            $lb = 0;
            foreach ( $slice as $o )
            {
                if ( $o[0] !== '+' )
                    $la++;
                if ( $o[0] !== '-' )
                    $lb++;
            }
            $hunks[] = array( 'a_start' => $la ? $posA[$r[0]] + 1 : $posA[$r[0]], 'a_len' => $la,
                              'b_start' => $lb ? $posB[$r[0]] + 1 : $posB[$r[0]], 'b_len' => $lb, 'ops' => $slice );
        }
        return $hunks;
    }
}
