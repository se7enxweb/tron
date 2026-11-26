<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The declarative steps between units. Each takes what earlier steps gave ("from", a reference) and gives a new
 * value; none of them reads or writes the installation. "each" is the one op that calls a unit, once per item, as the
 * same user and through the same checks as a unit step.
 *
 *   where     from, field, cmp, value       keep the items whose field compares (dates compare as dates)
 *   pick      from, fields[]                keep only these fields of every item (or of an object)
 *   map       from, fields{ new: "path" }   new items made of renamed fields
 *   sort      from, by, order (asc|desc)
 *   limit     from, count, offset
 *   count     from                          the number of items
 *   group     from, by                      [ { key, count } ] by count, largest first
 *   sum       from, field                   the sum of a numeric field
 *   min, max  from, field                   the smallest or largest value of a field
 *   distinct  from, field                   the distinct values of a field, sorted
 *   pluck     from, field                   the values of a field, in order
 *   flatten   from, field                   the lists under field of every item, joined (lanes -> items)
 *   age       from, field, as               adds as = whole days since the field's date to every item
 *   merge     values{ k: ref }              an object of resolved values
 *   text      template                      a text with {{$...}} references
 *   test      value, cmp, compare           true or false: whether the comparison holds (a check's verdict)
 *   assert    value, cmp, compare, message  stops the run with message when the comparison fails
 *   each      from, unit, args, max         calls unit once per item ($item in args), at most max (<= 50) times
 */
final class tronSkillOps
{
    const OPS = array( 'where', 'pick', 'map', 'sort', 'limit', 'count', 'group', 'sum', 'min', 'max', 'distinct', 'pluck',
                       'flatten', 'age', 'merge', 'text', 'test', 'assert', 'each' );
    const COMPARE = array( 'eq', 'ne', 'lt', 'le', 'gt', 'ge', 'in', 'nin', 'contains', 'prefix', 'empty', 'notempty', 'match' );

    /** Keys each op takes (besides id, op, when, note), the first ones required. */
    const SHAPE = array(
        'where' => array( array( 'from', 'field', 'cmp' ), array( 'value' ) ),
        'pick' => array( array( 'from', 'fields' ), array() ),
        'map' => array( array( 'from', 'fields' ), array() ),
        'sort' => array( array( 'from', 'by' ), array( 'order' ) ),
        'limit' => array( array( 'from', 'count' ), array( 'offset' ) ),
        'count' => array( array( 'from' ), array() ),
        'group' => array( array( 'from', 'by' ), array() ),
        'sum' => array( array( 'from', 'field' ), array() ),
        'min' => array( array( 'from', 'field' ), array() ),
        'max' => array( array( 'from', 'field' ), array() ),
        'distinct' => array( array( 'from', 'field' ), array() ),
        'pluck' => array( array( 'from', 'field' ), array() ),
        'flatten' => array( array( 'from' ), array( 'field' ) ),
        'age' => array( array( 'from', 'field', 'as' ), array() ),
        'merge' => array( array( 'values' ), array() ),
        'text' => array( array( 'template' ), array() ),
        'test' => array( array( 'value', 'cmp' ), array( 'compare' ) ),
        'assert' => array( array( 'value', 'cmp', 'message' ), array( 'compare' ) ),
        'each' => array( array( 'from', 'unit' ), array( 'args', 'max' ) ),
    );

    /** @return string[] */
    public static function declarationErrors( array $s ): array
    {
        $op = $s['op'] ?? null;
        if ( !is_string( $op ) || !isset( self::SHAPE[$op] ) )
            return array( 'op: one of ' . implode( ', ', self::OPS ) );
        list( $req, $opt ) = self::SHAPE[$op];
        $e = array();
        foreach ( $req as $k )
            if ( !array_key_exists( $k, $s ) )
                $e[] = "the op $op needs $k";
        foreach ( array_keys( $s ) as $k )
            if ( !in_array( $k, array_merge( array( 'id', 'op', 'when', 'note' ), $req, $opt ), true ) )
                $e[] = "the op $op takes no '$k'";
        if ( isset( $s['from'] ) && ( !is_string( $s['from'] ) || $s['from'] === '' || $s['from'][0] !== '$' ) )
            $e[] = 'from: a reference ($steps.<id>...)';
        if ( isset( $s['cmp'] ) && !in_array( $s['cmp'], self::COMPARE, true ) )
            $e[] = 'cmp: one of ' . implode( ', ', self::COMPARE );
        if ( isset( $s['cmp'] ) && !in_array( $s['cmp'], array( 'empty', 'notempty' ), true ) && !array_key_exists( in_array( $op, array( 'assert', 'test' ), true ) ? 'compare' : 'value', $s ) )
            $e[] = "cmp {$s['cmp']} needs " . ( in_array( $op, array( 'assert', 'test' ), true ) ? 'compare' : 'value' );
        if ( isset( $s['cmp'] ) && $s['cmp'] === 'match' )
        {
            $p = in_array( $op, array( 'assert', 'test' ), true ) ? ( $s['compare'] ?? '' ) : ( $s['value'] ?? '' );
            if ( !is_string( $p ) || ( ( $p === '' || $p[0] !== '$' ) && @preg_match( '/' . str_replace( '/', '\\/', $p ) . '/u', '' ) === false ) )
                $e[] = 'cmp match needs a valid regular expression';
        }
        if ( in_array( $op, array( 'pick' ), true ) && ( !is_array( $s['fields'] ?? null ) || !$s['fields'] || !tronSchemaValidator::isList( $s['fields'] ) ) )
            $e[] = 'fields: a list of field names';
        if ( $op === 'map' && ( !is_array( $s['fields'] ?? null ) || !$s['fields'] || tronSchemaValidator::isList( $s['fields'] ) ) )
            $e[] = 'fields: an object new name => field path';
        if ( $op === 'merge' && ( !is_array( $s['values'] ?? null ) || tronSchemaValidator::isList( $s['values'] ) ) )
            $e[] = 'values: an object';
        if ( isset( $s['order'] ) && !in_array( $s['order'], array( 'asc', 'desc' ), true ) )
            $e[] = 'order: asc or desc';
        if ( $op === 'limit' && !( is_int( $s['count'] ?? null ) && $s['count'] >= 1 && $s['count'] <= 1000 ) && !( is_string( $s['count'] ?? null ) && strpos( $s['count'], '$param.' ) === 0 ) )
            $e[] = 'count: 1 to 1000, or a $param reference';
        if ( $op === 'each' )
        {
            if ( !is_string( $s['unit'] ?? null ) || !preg_match( tronTool::NAME_PATTERN, $s['unit'] ) )
                $e[] = 'unit: a tool name';
            if ( isset( $s['max'] ) && !( is_int( $s['max'] ) && $s['max'] >= 1 && $s['max'] <= tronSkillManifest::MAX_EACH ) )
                $e[] = 'max: 1 to ' . tronSkillManifest::MAX_EACH;
        }
        if ( $op === 'text' && !is_string( $s['template'] ?? null ) )
            $e[] = 'template: a text';
        if ( $op === 'assert' && !is_string( $s['message'] ?? null ) )
            $e[] = 'message: a text';
        return $e;
    }

    /**
     * Runs one op. $call( unit, args ) runs a unit for "each" and returns its answer (or throws tronToolError).
     */
    public static function run( array $s, tronSkillExpr $x, callable $call )
    {
        $op = $s['op'];
        $from = isset( $s['from'] ) ? $x->resolve( $s['from'] ) : null;
        $list = is_array( $from ) ? ( tronSchemaValidator::isList( $from ) ? $from : array( $from ) ) : array();
        switch ( $op )
        {
            case 'where':
                $value = array_key_exists( 'value', $s ) ? $x->resolve( $s['value'] ) : null;
                return array_values( array_filter( $list, function ( $item ) use ( $s, $value ) {
                    return self::compare( tronSkillExpr::field( $item, $s['field'] ), $s['cmp'], $value );
                } ) );
            case 'pick':
                $pick = function ( $item ) use ( $s ) {
                    $o = array();
                    foreach ( $s['fields'] as $f )
                        $o[str_replace( '.', '_', $f )] = tronSkillExpr::field( $item, $f );
                    return $o;
                };
                return is_array( $from ) && !tronSchemaValidator::isList( $from ) ? $pick( $from ) : array_map( $pick, $list );
            case 'map':
                return array_map( function ( $item ) use ( $s ) {
                    $o = array();
                    foreach ( $s['fields'] as $k => $f )
                        $o[$k] = tronSkillExpr::field( $item, (string)$f );
                    return $o;
                }, $list );
            case 'sort':
                $by = $s['by'];
                $desc = ( $s['order'] ?? 'asc' ) === 'desc';
                usort( $list, function ( $a, $b ) use ( $by, $desc ) {
                    $va = tronSkillExpr::field( $a, $by );
                    $vb = tronSkillExpr::field( $b, $by );
                    if ( $va === $vb )
                        return 0;
                    if ( $va === null )
                        return 1;
                    if ( $vb === null )
                        return -1;
                    $c = is_numeric( $va ) && is_numeric( $vb ) ? ( $va <=> $vb ) : strcmp( (string)$va, (string)$vb );
                    return $desc ? -$c : $c;
                } );
                return $list;
            case 'limit':
                return array_slice( $list, (int)$x->resolve( $s['offset'] ?? 0 ), max( 1, (int)$x->resolve( $s['count'] ) ) );
            case 'count':
                return is_array( $from ) ? count( $list ) : ( $from === null ? 0 : 1 );
            case 'group':
                $g = array();
                foreach ( $list as $item )
                {
                    $keys = tronSkillExpr::field( $item, $s['by'] );
                    foreach ( is_array( $keys ) ? ( $keys ?: array( '' ) ) : array( $keys ) as $k )
                    {
                        $k = $k === null ? '' : ( is_scalar( $k ) ? (string)$k : json_encode( $k ) );
                        $g[$k] = ( $g[$k] ?? 0 ) + 1;
                    }
                }
                uksort( $g, function ( $a, $b ) use ( $g ) { return $g[$b] <=> $g[$a] ?: strcmp( (string)$a, (string)$b ); } );
                $out = array();
                foreach ( $g as $k => $n )
                    $out[] = array( 'key' => (string)$k, 'count' => $n );
                return $out;
            case 'sum':
                $t = 0;
                foreach ( $list as $item )
                {
                    $v = tronSkillExpr::field( $item, $s['field'] );
                    if ( is_numeric( $v ) )
                        $t += $v + 0;
                }
                return $t;
            case 'min': case 'max':
                $vals = array();
                foreach ( $list as $item )
                {
                    $v = tronSkillExpr::field( $item, $s['field'] );
                    if ( $v !== null && $v !== '' )
                        $vals[] = $v;
                }
                if ( !$vals )
                    return null;
                return $op === 'min' ? min( $vals ) : max( $vals );
            case 'distinct':
                $d = array();
                foreach ( $list as $item )
                {
                    $v = tronSkillExpr::field( $item, $s['field'] );
                    foreach ( is_array( $v ) ? $v : array( $v ) as $one )
                        if ( $one !== null && is_scalar( $one ) )
                            $d[(string)$one] = true;
                }
                $d = array_keys( $d );
                $d = array_map( 'strval', $d );
                sort( $d );
                return $d;
            case 'pluck':
                return array_map( function ( $item ) use ( $s ) { return tronSkillExpr::field( $item, $s['field'] ); }, $list );
            case 'flatten':
                $out = array();
                foreach ( $list as $item )
                {
                    $sub = isset( $s['field'] ) ? tronSkillExpr::field( $item, $s['field'] ) : $item;
                    if ( is_array( $sub ) && tronSchemaValidator::isList( $sub ) )
                        foreach ( $sub as $y )
                            $out[] = $y;
                    else if ( $sub !== null )
                        $out[] = $sub;
                }
                return $out;
            case 'age':
                $now = (int)$x->ref( '$now' );
                return array_map( function ( $item ) use ( $s, $now ) {
                    $t = self::time( tronSkillExpr::field( $item, $s['field'] ) );
                    if ( is_array( $item ) )
                        $item[$s['as']] = $t === null ? null : (int)floor( ( $now - $t ) / 86400 );
                    return $item;
                }, $list );
            case 'merge':
                return $x->resolve( $s['values'] );
            case 'text':
                return (string)$x->resolve( $s['template'] );
            case 'test':
                return self::compare( $x->resolve( $s['value'] ), $s['cmp'], array_key_exists( 'compare', $s ) ? $x->resolve( $s['compare'] ) : null );
            case 'assert':
                $v = $x->resolve( $s['value'] );
                $c = array_key_exists( 'compare', $s ) ? $x->resolve( $s['compare'] ) : null;
                if ( !self::compare( $v, $s['cmp'], $c ) )
                    throw new tronToolError( (string)$x->resolve( $s['message'] ) );
                return true;
            case 'each':
                $max = (int)( $s['max'] ?? 10 );
                $out = array();
                foreach ( array_slice( $list, 0, $max ) as $item )
                    $out[] = $call( $s['unit'], (array)$x->withItem( $item )->resolve( $s['args'] ?? array() ) );
                return $out;
        }
        throw new tronToolError( "Unknown op $op" );
    }

    /** Compares two values; when both read as dates (YYYY-MM-DD..., or unix times against a date) as times. */
    public static function compare( $a, string $cmp, $b ): bool
    {
        switch ( $cmp )
        {
            case 'empty':    return $a === null || $a === '' || $a === array() || $a === 0;
            case 'notempty': return !( $a === null || $a === '' || $a === array() || $a === 0 );
            case 'in':       return is_array( $b ) && in_array( $a, $b, false );
            case 'nin':      return !is_array( $b ) || !in_array( $a, $b, false );
            case 'contains':
                if ( is_array( $a ) )
                    return in_array( $b, $a, false );
                return $b !== null && $b !== '' && stripos( (string)$a, (string)$b ) !== false;
            case 'prefix':   return is_scalar( $a ) && strpos( (string)$a, (string)$b ) === 0;
            case 'match':    return is_scalar( $a ) && @preg_match( '/' . str_replace( '/', '\\/', (string)$b ) . '/u', (string)$a ) === 1;
        }
        if ( $a === null || $b === null )
            return $cmp === 'ne' ? $a !== $b : ( $cmp === 'eq' ? $a === $b : false );
        $ta = self::dateLike( $a ) ? self::time( $a ) : null;
        $tb = self::dateLike( $b ) ? self::time( $b ) : null;
        if ( $ta !== null && $tb !== null )
            $c = $ta <=> $tb;
        else if ( is_numeric( $a ) && is_numeric( $b ) )
            $c = ( $a + 0 ) <=> ( $b + 0 );
        else if ( is_bool( $a ) || is_bool( $b ) )
            $c = (bool)$a === (bool)$b ? 0 : 1;
        else
            $c = strcmp( (string)$a, (string)$b );
        switch ( $cmp )
        {
            case 'eq': return $c === 0;
            case 'ne': return $c !== 0;
            case 'lt': return $c < 0;
            case 'le': return $c <= 0;
            case 'gt': return $c > 0;
            case 'ge': return $c >= 0;
        }
        return false;
    }

    private static function dateLike( $v ): bool
    {
        return is_string( $v ) && preg_match( '/^\d{4}-\d{2}-\d{2}([T ]\d{2}:\d{2}(:\d{2})?(Z|[+-]\d{2}:?\d{2})?)?$/', $v );
    }

    /** A date text or unix time as unix time; null when it is neither. */
    public static function time( $v ): ?int
    {
        if ( is_int( $v ) || ( is_string( $v ) && ctype_digit( $v ) && strlen( $v ) >= 9 ) )
            return (int)$v;
        if ( self::dateLike( $v ) )
        {
            $t = strtotime( strlen( $v ) === 10 ? $v . ' 00:00:00 UTC' : $v );
            return $t === false ? null : $t;
        }
        return null;
    }
}
