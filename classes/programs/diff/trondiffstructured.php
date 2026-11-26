<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * Structured diffs: two values flattened to paths and compared path by path. Lists compare by position, except a
 * list of objects that all carry the same identifying key (id, identifier, name, key), which compares by that key so
 * an inserted item is one addition and not a change of every later one.
 *
 * Paths: JSON and YAML "$.a.b[0]", INI "[Block] Name" / "[Block] Name[]#0" / "[Block] Name[key]", XML
 * "/root/item[2]/@attr" and "/root/item[2]/text()".
 */
final class tronDiffStructured
{
    const ID_KEYS = array( 'id', 'identifier', 'name', 'key' );

    /** @return array[] changes: op (add, remove, change), path, from, to */
    public static function compare( $left, $right, string $root = '$' ): array
    {
        $out = array();
        self::walk( $left, $right, $root, $out );
        return $out;
    }

    private static function isAssoc( $v ): bool
    {
        return is_array( $v ) && $v !== array() && array_keys( $v ) !== range( 0, count( $v ) - 1 );
    }

    private static function idKey( array $list ): ?string
    {
        if ( !$list )
            return null;
        foreach ( self::ID_KEYS as $k )
        {
            $ok = true;
            $seen = array();
            foreach ( $list as $item )
            {
                if ( !is_array( $item ) || !isset( $item[$k] ) || !is_scalar( $item[$k] ) || isset( $seen[(string)$item[$k]] ) )
                {
                    $ok = false;
                    break;
                }
                $seen[(string)$item[$k]] = true;
            }
            if ( $ok )
                return $k;
        }
        return null;
    }

    private static function walk( $l, $r, string $path, array &$out ): void
    {
        if ( is_array( $l ) && is_array( $r ) )
        {
            $listL = !self::isAssoc( $l );
            $listR = !self::isAssoc( $r );
            if ( $listL && $listR )
            {
                $k = self::idKey( $l );
                if ( $k !== null && self::idKey( $r ) === $k )
                {
                    $li = array();
                    foreach ( $l as $item )
                        $li[(string)$item[$k]] = $item;
                    $ri = array();
                    foreach ( $r as $item )
                        $ri[(string)$item[$k]] = $item;
                    foreach ( $li as $id => $item )
                    {
                        $p = $path . '[' . $k . '=' . $id . ']';
                        if ( !array_key_exists( $id, $ri ) )
                            $out[] = array( 'op' => 'remove', 'path' => $p, 'from' => $item, 'to' => null );
                        else
                            self::walk( $item, $ri[$id], $p, $out );
                    }
                    foreach ( $ri as $id => $item )
                        if ( !array_key_exists( $id, $li ) )
                            $out[] = array( 'op' => 'add', 'path' => $path . '[' . $k . '=' . $id . ']', 'from' => null, 'to' => $item );
                    return;
                }
                $n = max( count( $l ), count( $r ) );
                for ( $i = 0; $i < $n; $i++ )
                {
                    $p = $path . '[' . $i . ']';
                    if ( !array_key_exists( $i, $l ) )
                        $out[] = array( 'op' => 'add', 'path' => $p, 'from' => null, 'to' => $r[$i] );
                    else if ( !array_key_exists( $i, $r ) )
                        $out[] = array( 'op' => 'remove', 'path' => $p, 'from' => $l[$i], 'to' => null );
                    else
                        self::walk( $l[$i], $r[$i], $p, $out );
                }
                return;
            }
            if ( !$listL && !$listR || $l === array() || $r === array() )
            {
                foreach ( $l as $k => $v )
                {
                    $p = self::key( $path, (string)$k );
                    if ( !array_key_exists( $k, $r ) )
                        $out[] = array( 'op' => 'remove', 'path' => $p, 'from' => $v, 'to' => null );
                    else
                        self::walk( $v, $r[$k], $p, $out );
                }
                foreach ( $r as $k => $v )
                    if ( !array_key_exists( $k, $l ) )
                        $out[] = array( 'op' => 'add', 'path' => self::key( $path, (string)$k ), 'from' => null, 'to' => $v );
                return;
            }
        }
        if ( $l !== $r )
            $out[] = array( 'op' => 'change', 'path' => $path, 'from' => $l, 'to' => $r );
    }

    private static function key( string $path, string $k ): string
    {
        if ( $path !== '' && $path[0] === '[' && strpos( $path, ']' ) === strlen( $path ) - 1 )
            return $path . ' ' . $k;              // INI: "[Block]" then "Name"
        return preg_match( '/^[A-Za-z_][A-Za-z0-9_-]*$/', $k ) ? $path . '.' . $k : $path . '[' . json_encode( $k, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) . ']';
    }

    /** added, removed, changed */
    public static function summary( array $changes ): array
    {
        $s = array( 'added' => 0, 'removed' => 0, 'changed' => 0 );
        foreach ( $changes as $c )
            $s[array( 'add' => 'added', 'remove' => 'removed', 'change' => 'changed' )[$c['op']]]++;
        return $s;
    }

    /** INI blocks compared block by block: paths "[Block] Name". */
    public static function ini( array $l, array $r ): array
    {
        $out = array();
        foreach ( $l as $b => $settings )
        {
            if ( !array_key_exists( $b, $r ) )
            {
                $out[] = array( 'op' => 'remove', 'path' => "[$b]", 'from' => $settings, 'to' => null );
                continue;
            }
            foreach ( $settings as $name => $v )
            {
                $p = "[$b] $name";
                if ( !array_key_exists( $name, $r[$b] ) )
                    $out[] = array( 'op' => 'remove', 'path' => $p, 'from' => $v, 'to' => null );
                else if ( $v !== $r[$b][$name] )
                    $out[] = array( 'op' => 'change', 'path' => $p, 'from' => $v, 'to' => $r[$b][$name] );
            }
            foreach ( $r[$b] as $name => $v )
                if ( !array_key_exists( $name, $settings ) )
                    $out[] = array( 'op' => 'add', 'path' => "[$b] $name", 'from' => null, 'to' => $v );
        }
        foreach ( $r as $b => $settings )
            if ( !array_key_exists( $b, $l ) )
                $out[] = array( 'op' => 'add', 'path' => "[$b]", 'from' => null, 'to' => $settings );
        return $out;
    }

    /** An XML document as path => value pairs (elements numbered among their same-named siblings). */
    public static function xmlFlat( DOMDocument $doc ): array
    {
        $out = array();
        if ( $doc->documentElement )
            self::xmlWalk( $doc->documentElement, '/' . $doc->documentElement->nodeName, $out );
        return $out;
    }

    private static function xmlWalk( DOMElement $e, string $path, array &$out ): void
    {
        foreach ( $e->attributes as $a )
            $out[$path . '/@' . $a->nodeName] = $a->nodeValue;
        $text = '';
        $counts = array();
        foreach ( $e->childNodes as $c )
        {
            if ( $c instanceof DOMElement )
            {
                $counts[$c->nodeName] = ( $counts[$c->nodeName] ?? 0 ) + 1;
                self::xmlWalk( $c, $path . '/' . $c->nodeName . '[' . $counts[$c->nodeName] . ']', $out );
            }
            else if ( $c instanceof DOMText || $c instanceof DOMCdataSection )
                $text .= $c->nodeValue;
        }
        if ( trim( $text ) !== '' )
            $out[$path . '/text()'] = trim( $text );
    }

    /** Two flat path => value maps compared. */
    public static function flat( array $l, array $r ): array
    {
        $out = array();
        foreach ( $l as $p => $v )
        {
            if ( !array_key_exists( $p, $r ) )
                $out[] = array( 'op' => 'remove', 'path' => (string)$p, 'from' => $v, 'to' => null );
            else if ( $r[$p] !== $v )
                $out[] = array( 'op' => 'change', 'path' => (string)$p, 'from' => $v, 'to' => $r[$p] );
        }
        foreach ( $r as $p => $v )
            if ( !array_key_exists( $p, $l ) )
                $out[] = array( 'op' => 'add', 'path' => (string)$p, 'from' => null, 'to' => $v );
        return $out;
    }
}
