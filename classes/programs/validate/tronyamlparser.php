<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * A YAML reader for configuration files, with findings instead of exceptions. It reads the part of YAML 1.2 that
 * configuration uses: block mappings and sequences, plain, single- and double-quoted scalars, literal (|) and folded
 * (>) block scalars with chomping, flow sequences and mappings ([a, b], {a: 1}), comments, a leading "---". The PHP
 * yaml extension or Symfony YAML are used instead when they are installed, for reading; the findings of tron's own
 * pass (tabs, duplicate keys, indentation) are always added.
 *
 * Findings: yaml.syntax, yaml.tab, yaml.indent, yaml.duplicate-key, yaml.unsupported (anchors, aliases, tags,
 * complex keys, several documents), yaml.quote.
 */
final class tronYamlParser
{
    /** @var array[] */ private $lines = array();
    /** @var int */ private $pos = 0;
    /** @var array[] */ public $findings = array();

    /** @return array( value, findings ) */
    public static function parse( string $text ): array
    {
        $p = new self();
        $value = null;
        try
        {
            $p->prepare( $text );
            $value = $p->block( 0 );
            if ( $p->pos < count( $p->lines ) )
                $p->finding( 'error', 'yaml.indent', 'Unexpected indentation', $p->lines[$p->pos]['n'] );
        }
        catch ( UnexpectedValueException $e )
        {
            // already recorded
        }
        return array( $value, $p->findings );
    }

    private function finding( string $sev, string $rule, string $msg, ?int $line, ?int $col = null ): void
    {
        $this->findings[] = array( 'severity' => $sev, 'rule' => $rule, 'message' => $msg, 'location' => array( 'line' => $line, 'column' => $col, 'path' => null ) );
    }

    private function fail( string $msg, int $line, ?int $col = null, string $rule = 'yaml.syntax' ): void
    {
        $this->finding( 'error', $rule, $msg, $line, $col );
        throw new UnexpectedValueException( $msg );
    }

    private function prepare( string $text ): void
    {
        $docs = 0;
        foreach ( preg_split( '/\r\n|\r|\n/', $text ) as $i => $raw )
        {
            $n = $i + 1;
            if ( preg_match( '/^(---|\.\.\.)(\s|$)/', $raw ) )
            {
                if ( $raw[0] === '-' && ++$docs > 1 )
                    $this->finding( 'warning', 'yaml.unsupported', 'Only the first document of a stream is read', $n );
                continue;
            }
            if ( $docs > 1 )
                continue;
            if ( preg_match( '/^%/', $raw ) )
                continue;
            if ( preg_match( '/^( *)\t/', $raw ) )
            {
                $this->finding( 'error', 'yaml.tab', 'A tab in the indentation (YAML allows spaces only)', $n );
                $raw = str_replace( "\t", '  ', $raw );
            }
            $indent = strlen( $raw ) - strlen( ltrim( $raw, ' ' ) );
            $this->lines[] = array( 'n' => $n, 'indent' => $indent, 'text' => rtrim( substr( $raw, $indent ) ), 'raw' => $raw );
        }
    }

    /** Skips blank and comment lines. */
    private function skip(): void
    {
        while ( $this->pos < count( $this->lines ) && ( $this->lines[$this->pos]['text'] === '' || $this->lines[$this->pos]['text'][0] === '#' ) )
            $this->pos++;
    }

    /** A block node whose lines are indented at least $min. */
    private function block( int $min )
    {
        $this->skip();
        if ( $this->pos >= count( $this->lines ) )
            return null;
        $l = $this->lines[$this->pos];
        if ( $l['indent'] < $min )
            return null;
        if ( $l['text'] === '-' || strpos( $l['text'], '- ' ) === 0 )
            return $this->sequence( $l['indent'] );
        if ( $this->keyOf( $l['text'] ) !== null )
            return $this->mapping( $l['indent'] );
        // a lone scalar (possibly over several lines)
        $this->pos++;
        return $this->scalar( $l['text'], $l['n'] );
    }

    private function sequence( int $indent ): array
    {
        $out = array();
        while ( true )
        {
            $this->skip();
            if ( $this->pos >= count( $this->lines ) )
                break;
            $l = $this->lines[$this->pos];
            if ( $l['indent'] < $indent )
                break;
            if ( $l['indent'] > $indent )
                $this->fail( 'Unexpected indentation in a list', $l['n'], $l['indent'] + 1, 'yaml.indent' );
            if ( !( $l['text'] === '-' || strpos( $l['text'], '- ' ) === 0 ) )
            {
                if ( $this->keyOf( $l['text'] ) !== null )
                    $this->fail( 'A mapping key at the indentation of a list', $l['n'], $l['indent'] + 1, 'yaml.indent' );
                break;
            }
            $rest = ltrim( substr( $l['text'], 1 ) );
            if ( $rest === '' || $rest[0] === '#' )
            {
                $this->pos++;
                $out[] = $this->block( $indent + 1 );
                continue;
            }
            // "- key: value" opens a mapping whose keys sit at the item's text column
            $col = $indent + ( strlen( $l['text'] ) - strlen( $rest ) );
            $this->lines[$this->pos] = array( 'n' => $l['n'], 'indent' => $col, 'text' => $rest, 'raw' => $l['raw'] );
            if ( strpos( $rest, '- ' ) === 0 || $rest === '-' )
                $out[] = $this->sequence( $col );
            else if ( $this->keyOf( $rest ) !== null )
                $out[] = $this->mapping( $col );
            else
            {
                $this->pos++;
                $out[] = $this->value( $rest, $l['n'], $col );
            }
        }
        return $out;
    }

    private function mapping( int $indent ): array
    {
        $out = array();
        $seen = array();
        while ( true )
        {
            $this->skip();
            if ( $this->pos >= count( $this->lines ) )
                break;
            $l = $this->lines[$this->pos];
            if ( $l['indent'] < $indent )
                break;
            if ( $l['indent'] > $indent )
                $this->fail( 'Unexpected indentation in a mapping', $l['n'], $l['indent'] + 1, 'yaml.indent' );
            $kv = $this->keyOf( $l['text'] );
            if ( $kv === null )
            {
                if ( $l['text'] === '-' || strpos( $l['text'], '- ' ) === 0 )
                    $this->fail( 'A list item at the indentation of a mapping', $l['n'], $l['indent'] + 1, 'yaml.indent' );
                $this->fail( 'Expected "key: value"', $l['n'], $l['indent'] + 1 );
            }
            list( $key, $rest ) = $kv;
            if ( isset( $seen[$key] ) )
                $this->finding( 'error', 'yaml.duplicate-key', "The key '$key' appears twice in one mapping (first on line {$seen[$key]})", $l['n'], $l['indent'] + 1 );
            $seen[$key] = $l['n'];
            $this->pos++;
            if ( $rest === '' || $rest[0] === '#' )
            {
                // the value is the indented block below; a list may sit at the key's own indentation
                $this->skip();
                $next = $this->lines[$this->pos] ?? null;
                if ( $next && $next['indent'] === $indent && ( $next['text'] === '-' || strpos( $next['text'], '- ' ) === 0 ) )
                    $out[$key] = $this->sequence( $indent );
                else
                    $out[$key] = $this->block( $indent + 1 );
            }
            else
                $out[$key] = $this->value( $rest, $l['n'], $indent );
        }
        return $out;
    }

    /** array( key, rest ) when the line is "key: ..." (quoted keys allowed), else null. */
    private function keyOf( string $t ): ?array
    {
        if ( $t === '' || $t[0] === '#' || $t[0] === '-' && ( strlen( $t ) === 1 || $t[1] === ' ' ) )
            return null;
        if ( $t[0] === '?' && ( strlen( $t ) === 1 || $t[1] === ' ' ) )
            return null;
        if ( $t[0] === '"' || $t[0] === "'" )
        {
            if ( preg_match( $t[0] === '"' ? '/^"((?:[^"\\\\]|\\\\.)*)"\s*:(\s|$)(.*)$/' : "/^'((?:[^']|'')*)'\s*:(\s|$)(.*)$/", $t, $m ) )
                return array( $t[0] === '"' ? stripcslashes( $m[1] ) : str_replace( "''", "'", $m[1] ), trim( $m[3] ) );
            return null;
        }
        if ( $t[0] === '[' || $t[0] === '{' )
            return null;
        if ( preg_match( '/^([^#]*?[^\s#])\s*:(\s+(.*)|$)/', $t, $m ) && strpos( $m[1], ': ' ) === false )
            return array( $m[1], isset( $m[3] ) ? trim( $m[3] ) : '' );
        return null;
    }

    /** The value after "key:" or "- ": block scalar, flow collection, or scalar. */
    private function value( string $rest, int $n, int $indent )
    {
        if ( preg_match( '/^[&*!]/', $rest ) )
        {
            $what = array( '&' => 'Anchors', '*' => 'Aliases', '!' => 'Tags' )[$rest[0]];
            $this->finding( 'warning', 'yaml.unsupported', "$what are not read by this reader; the value is taken as text", $n );
            if ( $rest[0] === '&' || $rest[0] === '!' )
            {
                $rest = trim( (string)preg_replace( '/^\S+\s*/', '', $rest ) );
                if ( $rest === '' )
                    return $this->block( $indent + 1 );
            }
        }
        if ( preg_match( '/^([|>])([+-]?)(\d?)([+-]?)\s*(#.*)?$/', $rest, $m ) )
            return $this->blockScalar( $m[1], $m[2] . $m[4], $indent );
        if ( $rest[0] === '[' || $rest[0] === '{' )
        {
            $text = $rest;
            // a flow collection may continue on the next lines
            while ( !self::balanced( $text ) && $this->pos < count( $this->lines ) )
                $text .= ' ' . $this->lines[$this->pos++]['text'];
            $i = 0;
            $v = $this->flow( $text, $i, $n );
            $tail = trim( substr( $text, $i ) );
            if ( $tail !== '' && $tail[0] !== '#' )
                $this->fail( 'Text after a flow collection', $n );
            return $v;
        }
        // a plain scalar may fold over more indented lines
        if ( $rest[0] !== '"' && $rest[0] !== "'" )
            while ( $this->pos < count( $this->lines ) && $this->lines[$this->pos]['indent'] > $indent && $this->lines[$this->pos]['text'] !== ''
                    && $this->keyOf( $this->lines[$this->pos]['text'] ) === null && $this->lines[$this->pos]['text'][0] !== '#' )
                $rest .= ' ' . $this->lines[$this->pos++]['text'];
        return $this->scalar( $rest, $n );
    }

    private static function balanced( string $t ): bool
    {
        $d = 0;
        $q = null;
        for ( $i = 0, $len = strlen( $t ); $i < $len; $i++ )
        {
            $c = $t[$i];
            if ( $q !== null )
            {
                if ( $c === '\\' && $q === '"' )
                    $i++;
                else if ( $c === $q )
                    $q = null;
                continue;
            }
            if ( $c === '"' || $c === "'" )
                $q = $c;
            else if ( $c === '[' || $c === '{' )
                $d++;
            else if ( $c === ']' || $c === '}' )
                $d--;
        }
        return $d <= 0;
    }

    private function blockScalar( string $style, string $chomp, int $indent ): string
    {
        $parts = array();
        $blockIndent = null;
        while ( $this->pos < count( $this->lines ) )
        {
            $l = $this->lines[$this->pos];
            if ( $l['text'] !== '' && $l['indent'] <= $indent )
                break;
            if ( $l['text'] !== '' && $blockIndent === null )
                $blockIndent = $l['indent'];
            $parts[] = $l['text'] === '' ? '' : str_repeat( ' ', max( 0, $l['indent'] - (int)$blockIndent ) ) . $l['text'];
            $this->pos++;
        }
        $trailing = 0;
        while ( $parts && end( $parts ) === '' )
        {
            array_pop( $parts );
            $trailing++;
        }
        if ( $style === '|' )
            $s = implode( "\n", $parts );
        else
        {
            $s = '';
            foreach ( $parts as $i => $p )
                $s .= $i === 0 ? $p : ( $p === '' || $parts[$i - 1] === '' || $p[0] === ' ' ? "\n" : ' ' ) . $p;
        }
        if ( $chomp === '-' )
            return $s;
        if ( $chomp === '+' )
            return $s . str_repeat( "\n", $trailing + 1 );
        return $s === '' ? '' : $s . "\n";
    }

    private function flow( string $t, int &$i, int $n )
    {
        $ws = function () use ( $t, &$i ) {
            while ( $i < strlen( $t ) && ( $t[$i] === ' ' ) )
                $i++;
        };
        $ws();
        $open = $t[$i] ?? '';
        if ( $open === '[' || $open === '{' )
        {
            $close = $open === '[' ? ']' : '}';
            $i++;
            $out = array();
            while ( true )
            {
                $ws();
                if ( $i >= strlen( $t ) )
                    $this->fail( "A flow collection is not closed with $close", $n );
                if ( $t[$i] === $close )
                {
                    $i++;
                    return $out;
                }
                if ( $open === '{' )
                {
                    $key = $this->flowScalar( $t, $i, $n, ':' );
                    $ws();
                    if ( ( $t[$i] ?? '' ) !== ':' )
                        $this->fail( 'Expected ":" in a flow mapping', $n );
                    $i++;
                    $ws();
                    if ( array_key_exists( (string)$key, $out ) )
                        $this->finding( 'error', 'yaml.duplicate-key', "The key '$key' appears twice in one mapping", $n );
                    $out[(string)$key] = ( $t[$i] ?? '' ) === '[' || ( $t[$i] ?? '' ) === '{' ? $this->flow( $t, $i, $n ) : $this->flowScalar( $t, $i, $n, ',}' );
                }
                else
                    $out[] = ( $t[$i] === '[' || $t[$i] === '{' ) ? $this->flow( $t, $i, $n ) : $this->flowScalar( $t, $i, $n, ',]' );
                $ws();
                if ( ( $t[$i] ?? '' ) === ',' )
                    $i++;
                else if ( ( $t[$i] ?? '' ) !== $close )
                    $this->fail( "Expected ',' or '$close' in a flow collection", $n );
            }
        }
        return $this->flowScalar( $t, $i, $n, ',]}' );
    }

    private function flowScalar( string $t, int &$i, int $n, string $stops )
    {
        $c = $t[$i] ?? '';
        if ( $c === '"' || $c === "'" )
        {
            $re = $c === '"' ? '/\G"((?:[^"\\\\]|\\\\.)*)"/' : "/\G'((?:[^']|'')*)'/";
            if ( !preg_match( $re, $t, $m, 0, $i ) )
                $this->fail( 'A quoted string is not closed', $n, null, 'yaml.quote' );
            $i += strlen( $m[0] );
            return $c === '"' ? self::unescape( $m[1] ) : str_replace( "''", "'", $m[1] );
        }
        $start = $i;
        while ( $i < strlen( $t ) && strpos( $stops, $t[$i] ) === false && !( $t[$i] === ':' && strpos( $stops, ':' ) !== false ) )
            $i++;
        return self::plain( trim( substr( $t, $start, $i - $start ) ) );
    }

    private function scalar( string $s, int $n )
    {
        $s = trim( $s );
        if ( $s === '' )
            return null;
        if ( $s[0] === '"' || $s[0] === "'" )
        {
            $re = $s[0] === '"' ? '/^"((?:[^"\\\\]|\\\\.)*)"\s*(#.*)?$/' : "/^'((?:[^']|'')*)'\s*(#.*)?$/";
            if ( !preg_match( $re, $s, $m ) )
                $this->fail( 'A quoted string is not closed, or text follows it', $n, null, 'yaml.quote' );
            return $s[0] === '"' ? self::unescape( $m[1] ) : str_replace( "''", "'", $m[1] );
        }
        $s = trim( (string)preg_replace( '/\s+#.*$/', '', $s ) );
        if ( preg_match( '/^[@`]/', $s ) )
            $this->fail( "A plain value cannot start with '{$s[0]}': quote it", $n, null, 'yaml.quote' );
        if ( preg_match( '/:\s/', $s ) )
            $this->fail( 'A plain value contains ": " (quote it, or it is a mapping at the wrong place)', $n );
        return self::plain( $s );
    }

    private static function unescape( string $s ): string
    {
        return (string)preg_replace_callback( '/\\\\(u[0-9a-fA-F]{4}|x[0-9a-fA-F]{2}|.)/', function ( $m ) {
            $c = $m[1];
            if ( $c[0] === 'u' || $c[0] === 'x' )
                return mb_chr( hexdec( substr( $c, 1 ) ), 'UTF-8' );
            return array( 'n' => "\n", 't' => "\t", 'r' => "\r", '0' => "\0", '"' => '"', '\\' => '\\', '/' => '/', ' ' => ' ', 'e' => "\033" )[$c] ?? $c;
        }, $s );
    }

    /** A plain scalar resolved by the YAML 1.2 core schema. */
    public static function plain( string $s )
    {
        if ( $s === '' || $s === '~' || preg_match( '/^(null|Null|NULL)$/', $s ) )
            return null;
        if ( preg_match( '/^(true|True|TRUE)$/', $s ) )
            return true;
        if ( preg_match( '/^(false|False|FALSE)$/', $s ) )
            return false;
        if ( preg_match( '/^[-+]?[0-9]+$/', $s ) )
            return (int)$s;
        if ( preg_match( '/^0x[0-9a-fA-F]+$/', $s ) )
            return hexdec( substr( $s, 2 ) );
        if ( preg_match( '/^0o[0-7]+$/', $s ) )
            return octdec( substr( $s, 2 ) );
        if ( preg_match( '/^[-+]?(\.[0-9]+|[0-9]+(\.[0-9]*)?)([eE][-+]?[0-9]+)?$/', $s ) )
            return (float)$s;
        return $s;
    }
}
