<?php
/**
 * @copyright Copyright (C) 1998 - 2026 Exponential Foundation and 7x; All rights reserved.
 * @license GNU General Public License v2.0 (or any later version)
 * @package tron
 */

/**
 * The wiring of a skill: references in argument and output values, resolved at run time. No code runs; a reference
 * only reads.
 *
 *   "$param.board"            a parameter of the run
 *   "$steps.list.items"       what an earlier step gave, a path into it
 *   "$item.id"                inside an "each" step: the current item
 *   "$now" "$today" "$week"   unix time, YYYY-MM-DD and the ISO week of the run (the server's clock)
 *   "$date.-30d" "$date.+2w"  a date relative to today (YYYY-MM-DD)
 *   "$ago.days" "$ahead.days" today minus or plus as many days as the parameter days says
 *   "$user.login"             the user the skill runs as (login, user_id)
 *   "$steps.list.items|count" a filter after the path (FILTERS)
 *   "Found {{$steps.n}} tickets"   references inside text
 *   "$$literal"               a literal text starting with one $
 *
 * A value that is a whole reference keeps its type (a list stays a list); a reference inside text becomes text.
 */
final class tronSkillExpr
{
    const FILTERS = array( 'count', 'first', 'last', 'keys', 'join', 'lower', 'upper', 'int', 'bool', 'not', 'empty', 'json', 'sum' );

    /** @var array */ private $scope;

    /**
     * @param array $scope param => array, steps => array, item => mixed, now => int, user => array( login, user_id )
     */
    public function __construct( array $scope )
    {
        $this->scope = $scope + array( 'param' => array(), 'steps' => array(), 'item' => null, 'now' => time(), 'user' => array() );
    }

    public function withItem( $item ): self
    {
        $c = clone $this;
        $c->scope['item'] = $item;
        return $c;
    }

    public function setStep( string $id, $value ): void
    {
        $this->scope['steps'][$id] = $value;
    }

    public function steps(): array
    {
        return $this->scope['steps'];
    }

    /** Resolves every reference in a value (recursively for arrays). */
    public function resolve( $v )
    {
        if ( is_array( $v ) )
        {
            $out = array();
            foreach ( $v as $k => $x )
                $out[$k] = $this->resolve( $x );
            return $out;
        }
        if ( !is_string( $v ) || $v === '' )
            return $v;
        if ( strpos( $v, '$$' ) === 0 )
            return substr( $v, 1 );
        if ( $v[0] === '$' )
            return $this->ref( $v );
        if ( strpos( $v, '{{' ) !== false )
            return (string)preg_replace_callback( '/\{\{\s*(\$[^}]+?)\s*\}\}/', function ( $m ) {
                $r = $this->ref( $m[1] );
                return is_scalar( $r ) || $r === null ? (string)( is_bool( $r ) ? ( $r ? 'yes' : 'no' ) : $r ) : json_encode( $r, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            }, $v );
        return $v;
    }

    public function ref( string $ref )
    {
        $bits = explode( '|', $ref, 2 );
        $parts = explode( '.', substr( $bits[0], 1 ) );
        $head = array_shift( $parts );
        $now = (int)$this->scope['now'];
        switch ( $head )
        {
            case 'now':   $v = $now; break;
            case 'today': $v = gmdate( 'Y-m-d', $now ); break;
            case 'week':  $v = gmdate( 'o-\WW', $now ); break;
            case 'date':
                $spec = (string)array_shift( $parts );
                $n = (int)substr( $spec, 0, -1 ) * ( substr( $spec, -1 ) === 'w' ? 7 : 1 );
                $v = gmdate( 'Y-m-d', $now + $n * 86400 );
                break;
            case 'ago': case 'ahead':
                $n = (int)( $this->scope['param'][(string)array_shift( $parts )] ?? 0 );
                $v = gmdate( 'Y-m-d', $now + ( $head === 'ago' ? -$n : $n ) * 86400 );
                break;
            case 'param': $v = $this->scope['param']; break;
            case 'steps': $v = $this->scope['steps']; break;
            case 'item':  $v = $this->scope['item']; break;
            case 'user':  $v = $this->scope['user']; break;
            default:
                throw new tronToolError( "Unknown reference $ref" );
        }
        $v = self::path( $v, $parts );
        return isset( $bits[1] ) ? self::filter( $v, $bits[1] ) : $v;
    }

    /** Follows a path of keys; a missing key gives null. A numeric part indexes a list. */
    public static function path( $v, array $parts )
    {
        foreach ( $parts as $p )
        {
            if ( $p === '' )
                continue;
            if ( is_array( $v ) && array_key_exists( $p, $v ) )
                $v = $v[$p];
            else if ( is_array( $v ) && ctype_digit( $p ) && array_key_exists( (int)$p, $v ) )
                $v = $v[(int)$p];
            else
                return null;
        }
        return $v;
    }

    /** A field of an item by dotted path ("assignee", "winner.path"). */
    public static function field( $item, string $field )
    {
        return self::path( $item, explode( '.', $field ) );
    }

    public static function filter( $v, string $f )
    {
        switch ( $f )
        {
            case 'count': return is_array( $v ) ? count( $v ) : ( $v === null ? 0 : 1 );
            case 'first': return is_array( $v ) && $v ? reset( $v ) : null;
            case 'last':  return is_array( $v ) && $v ? end( $v ) : null;
            case 'keys':  return is_array( $v ) ? array_keys( $v ) : array();
            case 'join':  return is_array( $v ) ? implode( ', ', array_map( function ( $x ) { return is_scalar( $x ) ? (string)$x : json_encode( $x ); }, $v ) ) : (string)$v;
            case 'lower': return strtolower( (string)$v );
            case 'upper': return strtoupper( (string)$v );
            case 'int':   return (int)$v;
            case 'bool':  return (bool)$v;
            case 'not':   return !$v;
            case 'empty': return $v === null || $v === '' || $v === array();
            case 'json':  return json_encode( $v, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
            case 'sum':   return is_array( $v ) ? array_sum( array_map( 'floatval', array_filter( $v, 'is_numeric' ) ) ) : (float)$v;
        }
        throw new tronToolError( "Unknown filter $f" );
    }
}
