<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The renderers of a text diff: unified, side by side (rows and fixed-width text), ANSI-coloured text for a
 * terminal, and HTML for the administration (admin4 component classes .a4-table and the module classes
 * .a4-tron-diff-*, all colours from tokens in stylesheets/modules/tron.css, light and dark; no inline style).
 *
 * Line granularity renders line by line. Word and character granularity render inline: [-removed-]{+added+} in text,
 * coloured runs in ANSI, <del>/<ins> in HTML.
 */
final class tronDiffRender
{
    const RED = "\033[31m", GREEN = "\033[32m", CYAN = "\033[36m", BOLD = "\033[1m", RESET = "\033[0m";

    /** @param string[] $a @param string[] $b */
    public static function unified( array $a, array $b, array $ops, int $context = 3, string $from = 'a', string $to = 'b', bool $ansi = false ): string
    {
        $c = function ( string $code, string $s ) use ( $ansi ) {
            return $ansi ? $code . $s . self::RESET : $s;
        };
        $out = $c( self::BOLD, "--- $from" ) . "\n" . $c( self::BOLD, "+++ $to" ) . "\n";
        foreach ( tronDiffEngine::hunks( $ops, $context ) as $h )
        {
            $out .= $c( self::CYAN, sprintf( '@@ -%d,%d +%d,%d @@', $h['a_start'], $h['a_len'], $h['b_start'], $h['b_len'] ) ) . "\n";
            foreach ( $h['ops'] as $o )
            {
                $line = $o[0] === '+' ? $b[$o[2]] : $a[$o[1]];
                $eol = substr( $line, -1 ) === "\n";
                $line = rtrim( $line, "\n" );
                $mark = $o[0] === '=' ? ' ' : $o[0];
                $text = $mark . $line;
                $out .= ( $o[0] === '-' ? $c( self::RED, $text ) : ( $o[0] === '+' ? $c( self::GREEN, $text ) : $text ) ) . "\n";
                if ( !$eol )
                    $out .= "\\ No newline at end of file\n";
            }
        }
        return $out;
    }

    /** Inline rendering for word and character diffs: [-x-]{+y+}, or coloured with $ansi. */
    public static function inline( array $a, array $b, array $ops, bool $ansi = false ): string
    {
        $out = '';
        $state = '=';
        $close = function () use ( &$state, &$out, $ansi ) {
            if ( $state === '-' )
                $out .= $ansi ? self::RESET : '-]';
            else if ( $state === '+' )
                $out .= $ansi ? self::RESET : '+}';
            $state = '=';
        };
        foreach ( $ops as $o )
        {
            if ( $o[0] !== $state )
            {
                $close();
                if ( $o[0] === '-' )
                    $out .= $ansi ? self::RED . "\033[9m" : '[-';
                else if ( $o[0] === '+' )
                    $out .= $ansi ? self::GREEN . "\033[4m" : '{+';
                $state = $o[0];
            }
            $out .= $o[0] === '+' ? $b[$o[2]] : $a[$o[1]];
        }
        $close();
        return $out;
    }

    /**
     * Side by side rows of a line diff: op (same, change, del, add), left_no, left, right_no, right. A run of
     * deletions followed by insertions is paired row by row as changes.
     */
    public static function rows( array $a, array $b, array $ops, ?int $context = null ): array
    {
        $rows = array();
        $del = array();
        $ins = array();
        $flush = function () use ( &$rows, &$del, &$ins, $a, $b ) {
            $n = max( count( $del ), count( $ins ) );
            for ( $i = 0; $i < $n; $i++ )
            {
                $l = $del[$i] ?? null;
                $r = $ins[$i] ?? null;
                $rows[] = array( 'op' => $l !== null && $r !== null ? 'change' : ( $l !== null ? 'del' : 'add' ),
                                 'left_no' => $l !== null ? $l + 1 : null, 'left' => $l !== null ? rtrim( $a[$l], "\n" ) : null,
                                 'right_no' => $r !== null ? $r + 1 : null, 'right' => $r !== null ? rtrim( $b[$r], "\n" ) : null );
            }
            $del = $ins = array();
        };
        foreach ( $ops as $o )
        {
            if ( $o[0] === '-' )
            {
                if ( $ins )
                    $flush();
                $del[] = $o[1];
            }
            else if ( $o[0] === '+' )
                $ins[] = $o[2];
            else
            {
                $flush();
                $rows[] = array( 'op' => 'same', 'left_no' => $o[1] + 1, 'left' => rtrim( $a[$o[1]], "\n" ), 'right_no' => $o[2] + 1, 'right' => rtrim( $b[$o[2]], "\n" ) );
            }
        }
        $flush();
        if ( $context === null )
            return $rows;
        // keep $context unchanged rows around changes, mark the gaps
        $keep = array();
        foreach ( $rows as $i => $r )
            if ( $r['op'] !== 'same' )
                for ( $k = max( 0, $i - $context ); $k <= min( count( $rows ) - 1, $i + $context ); $k++ )
                    $keep[$k] = true;
        $out = array();
        $gap = false;
        foreach ( $rows as $i => $r )
        {
            if ( isset( $keep[$i] ) )
            {
                $out[] = $r;
                $gap = false;
            }
            else if ( !$gap )
            {
                $out[] = array( 'op' => 'gap', 'left_no' => null, 'left' => null, 'right_no' => null, 'right' => null );
                $gap = true;
            }
        }
        return $out;
    }

    /** The rows as fixed-width text columns (for a terminal), $width characters a side. */
    public static function sideBySideText( array $rows, int $width = 60, bool $ansi = false ): string
    {
        $fit = function ( ?string $s ) use ( $width ) {
            $s = str_replace( "\t", '    ', (string)$s );
            $len = mb_strlen( $s, 'UTF-8' );
            return $len > $width ? mb_substr( $s, 0, $width - 1, 'UTF-8' ) . '…' : $s . str_repeat( ' ', $width - $len );
        };
        $mark = array( 'same' => ' ', 'change' => '|', 'del' => '<', 'add' => '>', 'gap' => '~' );
        $out = '';
        foreach ( $rows as $r )
        {
            if ( $r['op'] === 'gap' )
            {
                $out .= str_repeat( ' ', 5 ) . str_repeat( '·', 3 ) . "\n";
                continue;
            }
            $ln = $r['left_no'] === null ? '    ' : sprintf( '%4d', $r['left_no'] );
            $rn = $r['right_no'] === null ? '    ' : sprintf( '%4d', $r['right_no'] );
            $left = $fit( $r['left'] );
            $right = $fit( $r['right'] );
            if ( $ansi && in_array( $r['op'], array( 'del', 'change' ), true ) )
                $left = self::RED . $left . self::RESET;
            if ( $ansi && in_array( $r['op'], array( 'add', 'change' ), true ) )
                $right = self::GREEN . $right . self::RESET;
            $out .= "$ln $left {$mark[$r['op']]} $rn $right\n";
        }
        return $out;
    }

    /** HTML-escaped text. */
    public static function e( ?string $s ): string
    {
        return htmlspecialchars( (string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8' );
    }

    /**
     * The administration's HTML of side by side rows: a table with the shared .a4-table and the module classes
     * .a4-tron-diff, .is-same/.is-change/.is-del/.is-add/.is-gap. A changed pair shows the words that differ as
     * <del>/<ins>. $caption is the table's accessible name.
     */
    public static function html( array $rows, string $caption = 'Differences', string $leftLabel = 'Left', string $rightLabel = 'Right' ): string
    {
        $h = '<div class="a4-table-wrap a4-tron-diff-wrap"><table class="a4-table a4-tron-diff">'
           . '<caption class="a4-sr-only">' . self::e( $caption ) . '</caption>'
           . '<thead><tr><th scope="col" class="a4-tron-diff-num"><span class="a4-sr-only">' . self::e( $leftLabel ) . ' #</span></th>'
           . '<th scope="col">' . self::e( $leftLabel ) . '</th>'
           . '<th scope="col" class="a4-tron-diff-num"><span class="a4-sr-only">' . self::e( $rightLabel ) . ' #</span></th>'
           . '<th scope="col">' . self::e( $rightLabel ) . '</th></tr></thead><tbody>';
        foreach ( $rows as $r )
        {
            if ( $r['op'] === 'gap' )
            {
                $h .= '<tr class="is-gap"><td colspan="4" class="a4-tron-diff-gap">&#8943;</td></tr>';
                continue;
            }
            list( $l, $rt ) = $r['op'] === 'change' ? self::pairHtml( (string)$r['left'], (string)$r['right'] ) : array( self::e( $r['left'] ), self::e( $r['right'] ) );
            $sr = array( 'del' => 'removed', 'add' => 'added', 'change' => 'changed', 'same' => '' )[$r['op']];
            $h .= '<tr class="is-' . $r['op'] . '">'
                . '<td class="a4-tron-diff-num">' . ( $r['left_no'] ?? '' ) . '</td>'
                . '<td class="a4-tron-diff-left">' . ( $sr !== '' && $r['left'] !== null ? '<span class="a4-sr-only">' . $sr . ': </span>' : '' ) . '<code>' . $l . '</code></td>'
                . '<td class="a4-tron-diff-num">' . ( $r['right_no'] ?? '' ) . '</td>'
                . '<td class="a4-tron-diff-right">' . ( $sr !== '' && $r['right'] !== null ? '<span class="a4-sr-only">' . $sr . ': </span>' : '' ) . '<code>' . $rt . '</code></td>'
                . '</tr>';
        }
        return $h . '</tbody></table></div>';
    }

    /** The two sides of a changed line with their differing words marked, HTML-escaped. */
    public static function pairHtml( string $left, string $right ): array
    {
        $a = tronDiffEngine::tokens( $left, 'word' );
        $b = tronDiffEngine::tokens( $right, 'word' );
        $ops = count( $a ) + count( $b ) > 4000 ? array_merge( array_map( function ( $i ) { return array( '-', $i, null ); }, array_keys( $a ) ),
                                                               array_map( function ( $j ) { return array( '+', null, $j ); }, array_keys( $b ) ) )
                                                : tronDiffEngine::diff( $a, $b );
        $l = '';
        $r = '';
        foreach ( $ops as $o )
        {
            if ( $o[0] === '=' )
            {
                $l .= self::e( $a[$o[1]] );
                $r .= self::e( $b[$o[2]] );
            }
            else if ( $o[0] === '-' )
                $l .= '<del>' . self::e( $a[$o[1]] ) . '</del>';
            else
                $r .= '<ins>' . self::e( $b[$o[2]] ) . '</ins>';
        }
        return array( str_replace( '</del><del>', '', $l ), str_replace( '</ins><ins>', '', $r ) );
    }

    /** HTML of an inline (word or character) diff: one block with <del>/<ins>. */
    public static function inlineHtml( array $a, array $b, array $ops, string $caption = 'Differences' ): string
    {
        $h = '';
        foreach ( $ops as $o )
            $h .= $o[0] === '=' ? self::e( $a[$o[1]] ) : ( $o[0] === '-' ? '<del>' . self::e( $a[$o[1]] ) . '</del>' : '<ins>' . self::e( $b[$o[2]] ) . '</ins>' );
        $h = str_replace( array( '</del><del>', '</ins><ins>' ), '', $h );
        return '<div class="a4-tron-diff-inline" role="group" aria-label="' . self::e( $caption ) . '"><pre><code>' . $h . '</code></pre></div>';
    }

    /** HTML of a structured diff: a table of path, change, from, to. */
    public static function changesHtml( array $changes, string $caption = 'Changes' ): string
    {
        $h = '<div class="a4-table-wrap a4-tron-diff-wrap"><table class="a4-table a4-tron-diff a4-tron-diff-changes"><caption class="a4-sr-only">' . self::e( $caption ) . '</caption>'
           . '<thead><tr><th scope="col">Path</th><th scope="col">Change</th><th scope="col">From</th><th scope="col">To</th></tr></thead><tbody>';
        $cls = array( 'add' => 'add', 'remove' => 'del', 'change' => 'change' );
        foreach ( $changes as $c )
            $h .= '<tr class="is-' . $cls[$c['op']] . '"><th scope="row"><code>' . self::e( $c['path'] ) . '</code></th><td>' . self::e( $c['op'] ) . '</td>'
                . '<td class="a4-tron-diff-left"><code>' . self::e( self::show( $c['from'] ?? null ) ) . '</code></td>'
                . '<td class="a4-tron-diff-right"><code>' . self::e( self::show( $c['to'] ?? null ) ) . '</code></td></tr>';
        return $h . '</tbody></table></div>';
    }

    public static function show( $v ): string
    {
        if ( $v === null )
            return '';
        return is_string( $v ) ? $v : (string)json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
    }

    /** A structured diff as text lines: "+ path: to", "- path: from", "~ path: from -> to" (coloured with $ansi). */
    public static function changesText( array $changes, bool $ansi = false ): string
    {
        $out = '';
        foreach ( $changes as $c )
        {
            if ( $c['op'] === 'add' )
                $line = '+ ' . $c['path'] . ': ' . self::show( $c['to'] );
            else if ( $c['op'] === 'remove' )
                $line = '- ' . $c['path'] . ': ' . self::show( $c['from'] );
            else
                $line = '~ ' . $c['path'] . ': ' . self::show( $c['from'] ) . ' -> ' . self::show( $c['to'] );
            if ( $ansi )
                $line = ( $c['op'] === 'add' ? self::GREEN : ( $c['op'] === 'remove' ? self::RED : "\033[33m" ) ) . $line . self::RESET;
            $out .= $line . "\n";
        }
        return $out;
    }
}
